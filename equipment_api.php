<?php
// ============================================================
// equipment_api.php  (plain PHP, database: primefit_db)
//
// GET   ?action=get_equipment   -> list every row in equipment_items
// GET   (no action)             -> same as get_equipment
// POST  action=add_equipment    -> { name, description, qty }
//                                  creates one equipment_items row and
//                                  returns the created record
//
// Matches the "Add Equipment" form fields:
//   Equipment Name        -> name
//   Equipment Description  -> description
//   Quantity              -> qty
//
// The barcode is generated automatically ("EQ-001", "EQ-002", ...),
// incrementing from the current highest EQ-### value in the table,
// since the form no longer collects a barcode manually.
//
// category / location / next_maintenance / image_url are left empty
// (no project default exists for them on create); status is "Available".
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
    $action = ($method === "GET") ? "get_equipment" : "add_equipment";
}

// ------------------------------------------------------------
// Helper: fetch a single equipment row as an assoc array
// ------------------------------------------------------------
function equipment_find(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM equipment_items WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ------------------------------------------------------------
// GET: return all equipment rows
// ------------------------------------------------------------
if ($action === "get_equipment") {
    if ($method !== "GET") {
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Use GET for get_equipment."]);
        exit();
    }

    $result = $conn->query("SELECT * FROM equipment_items ORDER BY id ASC");
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $conn->close();

    echo json_encode(["success" => true, "equipment" => $items]);
    exit();
}

// ------------------------------------------------------------
// POST: add a new equipment item
// ------------------------------------------------------------
if ($action === "add_equipment") {
    if ($method !== "POST") {
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Use POST for add_equipment."]);
        exit();
    }

    $name        = trim((string) ($body["name"] ?? ""));
    $description = trim((string) ($body["description"] ?? ""));
    $qtyRaw      = $body["qty"] ?? null;

    // Validation
    if ($name === "") {
        echo json_encode(["success" => false, "message" => "Equipment name is required."]);
        exit();
    }
    if (!is_numeric($qtyRaw) || (float) $qtyRaw < 0 || floor((float) $qtyRaw) != (float) $qtyRaw) {
        echo json_encode(["success" => false, "message" => "Quantity must be a non-negative whole number."]);
        exit();
    }
    $qty = (int) $qtyRaw;

    // Auto-generate the next barcode: EQ-### incrementing from current max.
    $maxNum = 0;
    $res = $conn->query("SELECT barcode FROM equipment_items WHERE barcode REGEXP '^EQ-[0-9]+'");
    while ($r = $res->fetch_assoc()) {
        if (preg_match('/^EQ-(\d+)/', $r["barcode"], $m)) {
            $n = (int) $m[1];
            if ($n > $maxNum) {
                $maxNum = $n;
            }
        }
    }

    // Insert, retrying if a concurrent insert grabbed the same barcode.
    $item     = null;
    $category = "";           // no default on create
    $status   = "Available";

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $barcode = sprintf("EQ-%03d", $maxNum + 1 + $attempt);

        try {
            $stmt = $conn->prepare(
                "INSERT INTO equipment_items
                    (barcode, name, category, qty, status, location, next_maintenance, description, image_url, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, NULL, NOW(), NOW())"
            );
            $stmt->bind_param("sssiss", $barcode, $name, $category, $qty, $status, $description);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            $item = equipment_find($conn, (int) $newId);
            break;
        } catch (mysqli_sql_exception $e) {
            if (isset($stmt)) {
                $stmt->close();
            }
            if ($conn->errno === 1062) {
                continue; // duplicate barcode, try the next number
            }
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Could not add equipment."]);
            $conn->close();
            exit();
        }
    }

    $conn->close();

    if ($item === null) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Could not generate a unique barcode."]);
        exit();
    }

    http_response_code(201);
    echo json_encode([
        "success"   => true,
        "message"   => "Equipment added.",
        "equipment" => $item,
    ]);
    exit();
}

http_response_code(400);
echo json_encode(["success" => false, "message" => "Unknown action."]);
