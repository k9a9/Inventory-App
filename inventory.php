<?php
require 'db.php';

// Get all inventory items
function getAllInventory($conn) {
    $sql = "SELECT * FROM inventory";
    $result = $conn->query($sql);
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    return $items;
}

// Update stock
function updateStock($conn, $itemId, $newStock) {
    $stmt = $conn->prepare("UPDATE inventory SET stock = ? WHERE id = ?");
    $stmt->bind_param('ii', $newStock, $itemId);
    $stmt->execute();
}

// Get low-stock alerts
function getLowStockAlerts($conn) {
    $sql = "SELECT * FROM inventory WHERE stock <= threshold";
    $result = $conn->query($sql);
    $alerts = [];
    while ($row = $result->fetch_assoc()) {
        $alerts[] = $row;
    }
    return $alerts;
}

// Add a new inventory item
function addItem($conn, $name, $stock, $threshold) {
    $stmt = $conn->prepare("INSERT INTO inventory (name, stock, threshold) VALUES (?, ?, ?)");
    $stmt->bind_param('sii', $name, $stock, $threshold);
    $stmt->execute();
}

// Delete item
function deleteItem($conn, $id) {
    $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}

// Update item (stock + threshold)
function editItem($conn, $id, $stock, $threshold) {
    $stmt = $conn->prepare("UPDATE inventory SET stock = ?, threshold = ? WHERE id = ?");
    $stmt->bind_param('iii', $stock, $threshold, $id);
    $stmt->execute();
}

// Get recent sales (last 30 days)
function getRecentSales($conn, $itemId) {
    $sql = "SELECT quantity_sold FROM sales_history WHERE item_id = ? ORDER BY sale_date DESC LIMIT 30";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $result = $stmt->get_result();

    $sales = [];
    while ($row = $result->fetch_assoc()) {
        $sales[] = $row['quantity_sold'];
    }
    return array_reverse($sales);
}

// ML API call for predicted threshold
function getMLThresholdPrediction($productId, $avgDailySales, $last7Days, $last30Days, $currentStock) {
    $data = json_encode([
        'product_id' => $productId,
        'avg_daily_sales' => $avgDailySales,
        'last_7_days' => $last7Days,
        'last_30_days' => $last30Days,
        'current_stock' => $currentStock
    ]);

    $ch = curl_init('http://localhost:5000/predict_threshold');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    if ($response === false) {
        return 'API Error';
    }
    curl_close($ch);

    $result = json_decode($response, true);
    return isset($result['predicted_threshold']) ? $result['predicted_threshold'] : 'Error';
}

// Get top 10 selling products last week
function getTopSellingProductsLastWeek($conn) {
    $sql = "
        SELECT 
            i.id,
            i.name,
            i.stock,
            i.threshold,
            i.last_updated,
            SUM(s.quantity_sold) AS total_sales
        FROM 
            inventory i
        JOIN 
            sales_history s ON i.id = s.item_id
        WHERE 
            s.sale_date BETWEEN (
                SELECT DATE_SUB(MAX(sale_date), INTERVAL 7 DAY) FROM sales_history
            ) AND (
                SELECT MAX(sale_date) FROM sales_history
            )
        GROUP BY 
            i.id, i.name, i.stock, i.threshold
        ORDER BY 
            total_sales DESC
        LIMIT 10
    ";

    $result = $conn->query($sql);
    $topProducts = [];
    while ($row = $result->fetch_assoc()) {
        $topProducts[] = $row;
    }
    return $topProducts;
}

// Update predicted threshold back to database
function updatePredictedThreshold($conn, $itemId, $predictedThreshold) {
    $stmt = $conn->prepare("UPDATE inventory SET predicted_threshold = ? WHERE id = ?");
    $stmt->bind_param('di', $predictedThreshold, $itemId);
    $stmt->execute();
}
?>
