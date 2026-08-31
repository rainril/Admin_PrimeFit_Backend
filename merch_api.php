<?php
// ============================================================
// merch_api.php  (plain PHP, database: primefit_db)
//
// GET   ?action=get_merch   -> list every row in merch_items
// GET   (no action)         -> same as get_merch
// POST  action=add_merch    -> { name, barcode, price }
//                              creates one merch_items row and
//                              returns the created record
//
// Matches the "Add Merch" form fields:
//   Merch Name -> name
//   Barcode    -> sku      (stored in the `sku` column)
//   Price      -> price
//
// stock / sold / revenue all start at 0.
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// ------------------------------------------------------------
// DB connection (local XAMPP / primefit_db, same as my-app/.env)
// ------------------------------------------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("127.0.0.1", "root", "", "primefit_db");
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit();
}

$method = $_SERVER["REQUEST_METHOD"];

// Request body: accept JSON or form-encoded
$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) {
    $body = $_POST;
}

$action = $_GET["action"] ?? $body["action"] ?? "";
if ($action === "") {
    $action = ($method === "GET") ? "get_merch" : "add_merch";
}

// ------------------------------------------------------------
// Helper: fetch a single merch row as an assoc array
// ------------------------------------------------------------
function merch_find(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM merch_items WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ------------------------------------------------------------
// GET: return all merch rows
// ------------------------------------------------------------
if ($action === "get_merch") {
    if ($method !== "GET") {
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Use GET for get_merch."]);
        exit();
    }

    $result = $conn->query("SELECT * FROM merch_items ORDER BY id ASC");
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $conn->close();

    echo json_encode(["success" => true, "merch" => $items]);
    exit();
}

// ------------------------------------------------------------
// POST: add a new merch item
// ------------------------------------------------------------
if ($action === "add_merch") {
    if ($method !== "POST") {
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Use POST for add_merch."]);
        exit();
    }

    $name     = trim((string) ($body["name"] ?? ""));
    $sku      = trim((string) ($body["barcode"] ?? ($body["sku"] ?? "")));
    $priceRaw = $body["price"] ?? null;

    // Validation
    if ($name === "") {
        echo json_encode(["success" => false, "message" => "Merch name is required."]);
        exit();
    }
    if ($sku === "") {
        echo json_encode(["success" => false, "message" => "Barcode is required."]);
        exit();
    }
    if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
        echo json_encode(["success" => false, "message" => "Price must be a non-negative number."]);
        exit();
    }
    $price = round((float) $priceRaw, 2);

    // Reject a duplicate barcode/sku up front for a clean message.
    $check = $conn->prepare("SELECT id FROM merch_items WHERE sku = ?");
    $check->bind_param("s", $sku);
    $check->execute();
    if ($check->get_result()->fetch_assoc()) {
        $check->close();
        $conn->close();
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "A merch item with that barcode already exists."]);
        exit();
    }
    $check->close();

    try {
        $stmt = $conn->prepare(
            "INSERT INTO merch_items
                (sku, name, price, stock, sold, revenue, image_url, created_at, updated_at)
             VALUES (?, ?, ?, 0, 0, 0, NULL, NOW(), NOW())"
        );
        $stmt->bind_param("ssd", $sku, $name, $price);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        $isDuplicate = ($conn->errno === 1062);
        $conn->close();
        http_response_code($isDuplicate ? 409 : 500);
        echo json_encode([
            "success" => false,
            "message" => $isDuplicate
                ? "A merch item with that barcode already exists."
                : "Could not add merch item.",
        ]);
        exit();
    }

    $item = merch_find($conn, (int) $newId);
    $conn->close();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Merch item added.",
        "merch"   => $item,
    ]);
    exit();
}

http_response_code(400);
echo json_encode(["success" => false, "message" => "Unknown action."]);
