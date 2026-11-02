<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PendingRegistrationController;
use App\Models\Account;
use App\Services\MemberAccountService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PendingRegistrationReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.memberaccount' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sqlite');
        DB::purge('memberaccount');
        $_ENV['QR_SECRET_KEY'] = 'test-qr-key';
        putenv('QR_SECRET_KEY=test-qr-key');
        Schema::create('admins', function (Blueprint $t) { $t->id(); $t->integer('account_id'); });
        DB::table('admins')->insert(['account_id' => 1]);
        $s = Schema::connection('memberaccount');
        $s->create('PendingRegistrations', function (Blueprint $t) {
            $t->increments('RegistrationID'); $t->string('FirstName'); $t->string('LastName');
            $t->string('Email'); $t->string('PasswordHash'); $t->string('Phone')->nullable();
            $t->string('DateOfBirth')->nullable(); $t->string('Gender')->nullable(); $t->string('Address')->nullable();
            $t->integer('PlanID'); $t->decimal('Amount', 10, 2); $t->string('PaymentMethod');
            $t->binary('Receipt'); $t->string('ReceiptMime'); $t->string('Status');
            $t->timestamp('CreatedAt')->nullable(); $t->timestamp('ReviewedAt')->nullable(); $t->integer('MemberID')->nullable();
        });
        $s->create('Members', function (Blueprint $t) {
            $t->increments('MemberID'); $t->string('FirstName'); $t->string('LastName'); $t->string('Email')->unique();
            $t->string('PasswordHash'); $t->string('Phone')->nullable(); $t->string('DateOfBirth')->nullable();
            $t->string('Gender')->nullable(); $t->string('Address')->nullable(); $t->string('QRCodeData')->nullable();
        });
        $s->create('Plans', function (Blueprint $t) { $t->increments('PlanID'); $t->integer('DurationMonths'); $t->string('DurationLabel'); $t->decimal('Price', 10, 2); });
        $s->create('Memberships', function (Blueprint $t) {
            $t->increments('MembershipID'); $t->integer('MemberID'); $t->integer('PlanID'); $t->date('StartDate');
            $t->date('NextRenewalDate'); $t->string('Status'); $t->integer('SessionCredits'); $t->integer('SessionsUsed');
        });
        $s->create('Payments', function (Blueprint $t) {
            $t->increments('PaymentID'); $t->integer('MemberID'); $t->integer('MembershipID')->nullable();
            $t->date('Date'); $t->decimal('Amount', 10, 2); $t->string('Method'); $t->string('Status'); $t->string('ReceiptURL')->nullable();
        });
        $db = DB::connection('memberaccount');
        $db->table('Plans')->insert(['PlanID' => 1, 'DurationMonths' => 4, 'DurationLabel' => '4 Months', 'Price' => 2400]);
        $db->table('PendingRegistrations')->insert([
            'RegistrationID' => 1, 'FirstName' => 'Test', 'LastName' => 'Member', 'Email' => 'test@example.invalid',
            'PasswordHash' => 'test-hash', 'PlanID' => 1, 'Amount' => 2400, 'PaymentMethod' => 'GCash',
            'Receipt' => 'test-image', 'ReceiptMime' => 'image/png', 'Status' => 'Pending', 'CreatedAt' => now(),
        ]);
    }

    private function adminRequest(): Request
    {
        $account = new Account(['role' => 'admin']);
        $account->id = 1;
        $request = Request::create('/');
        $request->setUserResolver(fn () => $account);
        return $request;
    }

    public function test_approval_creates_one_account_membership_and_paid_payment(): void
    {
        $db = DB::connection('memberaccount');
        $this->assertSame(0, $db->table('Members')->count());
        $controller = new PendingRegistrationController();
        $this->assertTrue($controller->approve($this->adminRequest(), 1)->getData(true)['success']);
        $this->assertSame(1, $db->table('Members')->count());
        $this->assertSame('Active', $db->table('Memberships')->value('Status'));
        $this->assertSame(120, (int) $db->table('Memberships')->value('SessionCredits'));
        $this->assertSame('Paid', $db->table('Payments')->value('Status'));
        $this->assertSame('Approved', $db->table('PendingRegistrations')->value('Status'));
        $this->assertSame('', $db->table('PendingRegistrations')->value('PasswordHash'));
        $this->assertCount(1, $controller->approvedPayments($this->adminRequest())->getData(true)['payments']);
        try { $controller->approve($this->adminRequest(), 1); $this->fail('Duplicate approval succeeded.'); }
        catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $this->assertSame(1, $db->table('Members')->count());
    }

    public function test_rejection_creates_no_account(): void
    {
        $response = (new PendingRegistrationController())->reject($this->adminRequest(), 1);
        $this->assertTrue($response->getData(true)['success']);
        $db = DB::connection('memberaccount');
        $this->assertSame('Rejected', $db->table('PendingRegistrations')->value('Status'));
        $this->assertSame(0, $db->table('Members')->count());
        $this->assertSame(0, $db->table('Memberships')->count());
    }

    public function test_review_requires_admin(): void
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => new Account(['role' => 'customer']));
        $this->expectException(HttpException::class);
        (new PendingRegistrationController())->approve($request, 1);
    }

    public function test_review_route_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/pending-registrations/1/approve')->assertUnauthorized();
        $this->getJson('/api/pending-registrations/1/receipt')->assertUnauthorized();
        $this->getJson('/api/reviewed-payments')->assertUnauthorized();
        $this->postJson('/api/register')->assertUnauthorized();
        $this->assertSame(0, DB::connection('memberaccount')->table('Members')->count());
    }

    public function test_legacy_pending_receipt_requires_a_review_before_it_is_paid(): void
    {
        $db = DB::connection('memberaccount');
        $db->table('Members')->insert(['MemberID' => 10, 'FirstName' => 'Legacy', 'LastName' => 'Member', 'Email' => 'legacy@example.invalid', 'PasswordHash' => 'hash']);
        $db->table('Memberships')->insert(['MembershipID' => 12, 'MemberID' => 10, 'PlanID' => 1, 'StartDate' => '2026-01-01', 'NextRenewalDate' => '2026-05-01', 'Status' => 'Active', 'SessionCredits' => 120, 'SessionsUsed' => 0]);
        $db->table('Payments')->insert(['PaymentID' => 15, 'MemberID' => 10, 'MembershipID' => 12, 'Date' => '2026-01-01', 'Amount' => 2400, 'Method' => 'GCash', 'Status' => 'Pending', 'ReceiptURL' => 'uploads/receipts/test.png']);
        Http::fake(['member-account-backend.onrender.com/*' => Http::response('image', 200, ['Content-Type' => 'image/png'])]);
        $this->assertFalse(MemberAccountService::deductSessionCredit(10)['success']);
        $controller = new PendingRegistrationController();
        $this->assertSame(2, count($controller->index($this->adminRequest())->getData(true)['pending']));
        $this->assertTrue($controller->reviewLegacy($this->adminRequest(), 15, 'approve')->getData(true)['success']);
        $this->assertSame('Paid', $db->table('Payments')->where('PaymentID', 15)->value('Status'));
        $this->assertSame(now()->toDateString(), $db->table('Memberships')->where('MembershipID', 12)->value('StartDate'));
        try { $controller->reviewLegacy($this->adminRequest(), 15, 'approve'); $this->fail('Duplicate approval succeeded.'); }
        catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
    }

    public function test_missing_legacy_receipt_cannot_be_approved(): void
    {
        $db = DB::connection('memberaccount');
        $db->table('Payments')->insert(['PaymentID' => 99, 'MemberID' => 1, 'MembershipID' => 1, 'Date' => '2026-01-01', 'Amount' => 100, 'Method' => 'GCash', 'Status' => 'Pending', 'ReceiptURL' => 'uploads/receipts/missing.png']);
        Http::fake(['member-account-backend.onrender.com/*' => Http::response('', 404)]);
        try { (new PendingRegistrationController())->reviewLegacy($this->adminRequest(), 99, 'approve'); $this->fail('Approval succeeded without a receipt.'); }
        catch (HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        $this->assertSame('Pending', $db->table('Payments')->where('PaymentID', 99)->value('Status'));
    }

    public function test_reviewing_an_unlinked_renewal_adds_credits_once(): void
    {
        $db = DB::connection('memberaccount');
        $db->table('Members')->insert(['MemberID' => 10, 'FirstName' => 'Test', 'LastName' => 'Member', 'Email' => 'renew@example.invalid', 'PasswordHash' => 'hash']);
        $db->table('Memberships')->insert(['MembershipID' => 12, 'MemberID' => 10, 'PlanID' => 1, 'StartDate' => now()->toDateString(), 'NextRenewalDate' => now()->addMonth()->toDateString(), 'Status' => 'Active', 'SessionCredits' => 120, 'SessionsUsed' => 10]);
        $db->table('Payments')->insert(['PaymentID' => 21, 'MemberID' => 10, 'MembershipID' => null, 'Date' => now()->toDateString(), 'Amount' => 2400, 'Method' => 'GCash', 'Status' => 'Pending', 'ReceiptURL' => 'uploads/receipts/test.png']);
        Http::fake(['member-account-backend.onrender.com/*' => Http::response('image', 200, ['Content-Type' => 'image/png'])]);
        $controller = new PendingRegistrationController();
        $this->assertTrue($controller->reviewLegacy($this->adminRequest(), 21, 'approve')->getData(true)['success']);
        $this->assertSame(240, (int) $db->table('Memberships')->where('MembershipID', 12)->value('SessionCredits'));
        $this->assertSame(12, (int) $db->table('Payments')->where('PaymentID', 21)->value('MembershipID'));
        $this->assertSame('Paid', $db->table('Payments')->where('PaymentID', 21)->value('Status'));
        try { $controller->reviewLegacy($this->adminRequest(), 21, 'approve'); $this->fail('Duplicate approval succeeded.'); }
        catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $this->assertSame(240, (int) $db->table('Memberships')->where('MembershipID', 12)->value('SessionCredits'));
    }

    public function test_reviewing_an_upgrade_activates_the_selected_plan(): void
    {
        $db = DB::connection('memberaccount');
        $db->table('Plans')->insert(['PlanID' => 2, 'DurationMonths' => 7, 'DurationLabel' => '7 Months', 'Price' => 3500]);
        $db->table('Members')->insert(['MemberID' => 10, 'FirstName' => 'Test', 'LastName' => 'Member', 'Email' => 'upgrade@example.invalid', 'PasswordHash' => 'hash']);
        $db->table('Memberships')->insert(['MembershipID' => 12, 'MemberID' => 10, 'PlanID' => 1, 'StartDate' => now()->toDateString(), 'NextRenewalDate' => now()->addMonth()->toDateString(), 'Status' => 'Active', 'SessionCredits' => 120, 'SessionsUsed' => 10]);
        $db->table('Payments')->insert(['PaymentID' => 21, 'MemberID' => 10, 'MembershipID' => null, 'Date' => now()->toDateString(), 'Amount' => 3500, 'Method' => 'GCash', 'Status' => 'Pending', 'ReceiptURL' => 'uploads/receipts/test.png']);
        Http::fake(['member-account-backend.onrender.com/*' => Http::response('image', 200, ['Content-Type' => 'image/png'])]);
        (new PendingRegistrationController())->reviewLegacy($this->adminRequest(), 21, 'approve');
        $this->assertSame('Expired', $db->table('Memberships')->where('MembershipID', 12)->value('Status'));
        $new = $db->table('Memberships')->where('MemberID', 10)->where('Status', 'Active')->first();
        $this->assertSame(2, (int) $new->PlanID);
        $this->assertSame(210, (int) $new->SessionCredits);
        $this->assertSame('Paid', $db->table('Payments')->where('PaymentID', 21)->value('Status'));
    }
}
