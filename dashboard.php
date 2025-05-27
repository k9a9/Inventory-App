<?php
require 'db.php';
require 'inventory.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_item'])) {
        addItem($conn, $_POST['name'], $_POST['stock'], $_POST['threshold']);
    }

    if (isset($_POST['edit_item'])) {
        editItem($conn, $_POST['id'], $_POST['stock'], $_POST['threshold']);
    }

    if (isset($_POST['delete_item'])) {
        deleteItem($conn, $_POST['id']);
    }
}

$allProducts = getAllInventory($conn);
$items = getTopSellingProductsLastWeek($conn);
$alerts = getLowStockAlerts($conn);

$productNames = [];
$stockLevels = [];
$thresholds = [];

foreach ($items as $item) {
    $productNames[] = $item['name'];
    $stockLevels[] = $item['stock'];
    $thresholds[] = $item['threshold'];
}

$selectedProduct = null;
$salesHistory = [];
$saleDates = [];
$saleQuantities = [];
$predictedThreshold = '';

if (isset($_GET['product_id']) && $_GET['product_id'] !== '') {
    $productId = intval($_GET['product_id']);
    foreach ($allProducts as $product) {
        if ($product['id'] == $productId) {
            $selectedProduct = $product;
            break;
        }
    }

    if ($selectedProduct) {
        $salesHistory = getRecentSales($conn, $selectedProduct['id']);
        $averageDailySales = array_sum($salesHistory) / max(count($salesHistory), 1);
        $last7Days = array_sum(array_slice($salesHistory, -7)) / min(7, count($salesHistory));
        $last30Days = array_sum(array_slice($salesHistory, -30)) / min(30, count($salesHistory));
        $currentStock = $selectedProduct['stock'];

        $predictedThreshold = getMLThresholdPrediction($selectedProduct['id'], $averageDailySales, $last7Days, $last30Days, $currentStock);
        if (!is_numeric($predictedThreshold)) {
            $predictedThreshold = 'Prediction Error';
        }

        $sql = "SELECT sale_date, quantity_sold FROM sales_history WHERE item_id = ? AND sale_date >= CURDATE() - INTERVAL 7 DAY ORDER BY sale_date ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $selectedProduct['id']);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $saleDates[] = $row['sale_date'];
            $saleQuantities[] = $row['quantity_sold'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="container mt-4">
    <h1>Inventory Dashboard</h1>
    <h2>Low stock alert</h2>
    <?php if (!empty($alerts)): ?>
        <div class="alert alert-danger">
            <ul>
            <?php foreach ($alerts as $alert): ?>
                <li><?= htmlspecialchars($alert['name']) ?> is low! (Stock: <?= $alert['stock'] ?>)</li>
            <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <h2>Top 10 selling products this week</h2>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th><th>Name</th><th>Stock</th><th>Predicted Threshold</th><th>Last Updated</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
        <?php
        $rowSalesHistory = getRecentSales($conn, $item['id']);
        $avgDailySales = array_sum($rowSalesHistory) / max(count($rowSalesHistory), 1);
        $last7Days = array_sum(array_slice($rowSalesHistory, -7)) / min(7, count($rowSalesHistory));
        $last30Days = array_sum(array_slice($rowSalesHistory, -30)) / min(30, count($rowSalesHistory));
        $currentStock = $item['stock'];

        $predictedThresholdRow = getMLThresholdPrediction($item['id'], $avgDailySales, $last7Days, $last30Days, $currentStock);
        if (!is_numeric($predictedThresholdRow)) {
            $predictedThresholdRow = 'Prediction Error';
        }
        ?>
        <tr>
            <td><?= $item['id'] ?></td>
            <td><?= htmlspecialchars($item['name']) ?></td>
            <td><?= $item['stock'] ?></td>
            <td><?= $predictedThresholdRow ?></td>
            <td><?= $item['last_updated'] ?></td>
            <td>
                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $item['id'] ?>">Edit</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                    <input type="hidden" name="delete_item" value="1">
                    <button class="btn btn-sm btn-danger" onclick="return confirm('Delete this item?')">Delete</button>
                </form>

                <div class="modal fade" id="editModal<?= $item['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="POST" class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit <?= htmlspecialchars($item['name']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <input name="stock" type="number" class="form-control mb-2" value="<?= $item['stock'] ?>" required>
                            <input name="threshold" type="number" class="form-control" value="<?= $item['threshold'] ?>" required>
                            <input type="hidden" name="edit_item" value="1">
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-primary" type="submit">Save</button>
                        </div>
                        </form>
                    </div>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Product Trend</h2>
    <form method="GET" class="mb-4">
        <label for="product" class="form-label">Select Product</label>
        <select name="product_id" id="product" class="form-select" onchange="this.form.submit()">
            <option value="">-- Select a product --</option>
            <?php foreach ($allProducts as $product): ?>
                <option value="<?= $product['id'] ?>" <?= (isset($_GET['product_id']) && $_GET['product_id'] == $product['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($product['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($selectedProduct): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h4><?= htmlspecialchars($selectedProduct['name']) ?> Details</h4>
                <p><strong>Stock:</strong> <?= $selectedProduct['stock'] ?></p>
                <p><strong>Predicted Threshold:</strong> <?= $predictedThreshold ?></p>
            </div>
        </div>
        <h4>Sales Trend (Last 7 Days)</h4>
        <canvas id="salesTrendChart" height="100"></canvas>
    <?php endif; ?>
</div>

<?php if ($selectedProduct): ?>
<script>
const saleDates = <?= json_encode($saleDates) ?>;
const saleQuantities = <?= json_encode($saleQuantities) ?>;
const ctx = document.getElementById('salesTrendChart').getContext('2d');
const salesTrendChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: saleDates,
        datasets: [{
            label: 'Units Sold',
            data: saleQuantities,
            borderColor: 'rgba(75, 192, 192, 1)',
            tension: 0.3,
            fill: false
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                precision: 0
            }
        }
    }
});
</script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>