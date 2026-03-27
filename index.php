<?php
// ---------- DATABASE CONNECTION ----------
$host = "cafe-db.chhcwyw7nw2u.us-east-1.rds.amazonaws.com";
$dbname = "cafeteria";
$username = "cafeadmin";
$password = "Cafe-pass1234";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo "Database connection failed: " . $e->getMessage();
    exit;
}

// ---------- SIMPLE API ROUTING ----------
if (isset($_GET['api'])) {
    header("Content-Type: application/json");

    if ($_GET['api'] === "categories") {
        $stmt = $pdo->query("SELECT DISTINCT Category AS name FROM MenuItem");
        $categories = [];
        $id = 1;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categories[] = ["id" => $id++, "name" => $row["name"], "icon" => "🍴"];
        }
        echo json_encode($categories);
        exit;
    }

    if ($_GET['api'] === "menu") {
        $stmt = $pdo->query("SELECT MenuID AS id, Name AS name, Category AS category_name, Price AS price, Image AS image_emoji FROM MenuItem");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item["is_available"] = 1;
        }
        echo json_encode($items);
        exit;
    }

    if ($_GET['api'] === "orders" && $_SERVER['REQUEST_METHOD'] === "POST") {
        $data = json_decode(file_get_contents("php://input"), true);

        $stmt = $pdo->prepare("INSERT INTO Orders (Total, OrderTime) VALUES (:total, NOW())");
        $stmt->execute(["total" => $data["total"]]);
        $orderId = $pdo->lastInsertId();

        foreach ($data["items"] as $item) {
            $stmt = $pdo->prepare("INSERT INTO OrderItem (OrderID, MenuID, Quantity, SubTotal) VALUES (:orderId, :menuId, :qty, :subtotal)");
            $stmt->execute([
                "orderId" => $orderId,
                "menuId" => $item["item_id"],
                "qty" => $item["qty"],
                "subtotal" => $item["line_total"]
            ]);
        }

        $stmt = $pdo->prepare("INSERT INTO Payment (OrderID, Method, PaymentTime) VALUES (:orderId, :method, NOW())");
        $stmt->execute([
            "orderId" => $orderId,
            "method" => $data["payment_method"]
        ]);

        echo json_encode(["order_id" => $orderId, "status" => "success", "db_status" => "Saved to AWS RDS MySQL"]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Sunny Side Diner | AWS RDS Order System</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="diner-wrap">
    <div class="diner-app">
        <div class="diner-header">
            <div class="diner-logo">Sunny Side Diner<span>AWS RDS · Fresh Daily</span></div>
            <div class="search-area">
                <input type="text" id="searchInput" class="search-input" placeholder="🔍 Search burger, fries...">
                <button class="search-btn" id="searchBtn">Search</button>
                <button class="search-btn" id="clearSearchBtn">Clear</button>
            </div>
            <div class="header-badge">🍽️ Live Menu</div>
        </div>
        <div class="sidebar" id="sidebarContainer">
            <div class="sidebar-title">✦ CATEGORIES ✦</div>
            <div id="categoryList"></div>
        </div>
        <div class="menu-area" id="menuArea">
            <div class="loading-overlay">Loading menu from AWS RDS...</div>
        </div>
        <div class="cart-panel">
            <div class="cart-header"><div class="cart-title">★ Your Order</div><div class="cart-count" id="cartCount">0 items</div></div>
            <div class="cart-items" id="cartItems"><div class="cart-empty">🧺 Basket is empty</div></div>
            <div class="cart-footer"><div class="cart-total"><span>Total</span><span id="totalAmount">$0.00</span></div><button class="checkout-btn" id="checkoutBtn">Place Order ★</button></div>
        </div>
    </div>

    <!-- CHECKOUT MODAL -->
    <div class="modal-overlay" id="modal">
        <div class="modal-box">
            <div class="modal-header"><h3>🧾 Checkout</h3><button class="modal-close" id="closeModalBtn">✕</button></div>
            <div class="modal-body">
                <div class="order-summary" id="modalSummary"></div>
                <div class="os-total"><span>Total Due</span><span id="modalTotal">$0.00</span></div>
                <div class="radio-group">
                    <label><input type="radio" name="orderType" value="Dine-in" checked> 🍽️ Dine-in</label>
                    <label><input type="radio" name="orderType" value="Takeaway"> 🛍️ Takeaway</label>
                </div>
                <div class="pay-methods">
                    <button class="pay-btn selected" data-pay="cash">💵 Cash</button>
                    <button class="pay-btn" data-pay="card">💳 Card</button>
                    <button class="pay-btn" data-pay="ewallet">📱 E-Wallet</button>
                </div>
                <div class="card-fields" id="cardFields">
                    <input class="field-input" id="cardNum" placeholder="Card number •••• •••• •••• ••••">
                </div>
                <button class="place-btn" id="submitOrderBtn"><span id="btnSpinner" class="spinner" style="display:none"></span> Confirm Order</button>
            </div>
        </div>
    </div>

    <!-- SUCCESS PAGE (FULL PAGE) -->
    <div id="successPage" class="success-page-overlay">
        <div class="success-card">
            <div class="success-icon">🎉</div>
            <div class="success-title">Payment Successful!</div>
            <div class="success-message">Your order has been received and is being prepared</div>
            <div class="order-id-box" id="successOrderId">#ORD-000000</div>
            <div class="order-details" id="successOrderDetails"></div>
            <div class="db-status-badge" id="successDbStatus">✓ Saved to AWS RDS MySQL</div>
            <button class="back-to-menu-btn" id="backToMenuBtn">← Back to Menu & Order More</button>
        </div>
    </div>

    <div class="toast" id="toastMsg"></div>
</div>

<script>
    const API_BASE = "index.php?api"; // PHP handles routing

    async function loadMenuData() {
        try {
            const catRes = await fetch(`${API_BASE}=categories`);
            const categories = await catRes.json();

            const menuRes = await fetch(`${API_BASE}=menu`);
            const fullMenuItems = await menuRes.json();

            // renderSidebar() and renderMenuByCategory() remain unchanged
        } catch (err) {
            console.error("Error loading menu:", err);
        }
    }

    async function submitOrder(payload) {
        const response = await fetch(`${API_BASE}=orders`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        console.log(result);
    }

    // Call loadMenuData() on page load
    loadMenuData();
</script>
</body>
</html>