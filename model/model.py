import pandas as pd
import numpy as np
from sklearn.tree import DecisionTreeRegressor
import joblib

# Load data
sales_df = pd.read_csv('data/sales.csv')
inventory_df = pd.read_csv('data/inventory.csv')

# Prepare features + targets
features = []
targets = []

for product_id in inventory_df['id']:
    product_sales = sales_df[sales_df['item_id'] == product_id].sort_values('sale_date')
    if product_sales.empty:
        continue

    avg_daily_sales = product_sales['quantity_sold'].mean()
    last_7_days = product_sales.tail(7)['quantity_sold'].mean()
    last_30_days = product_sales.tail(30)['quantity_sold'].mean()
    current_stock = inventory_df[inventory_df['id'] == product_id]['stock'].values[0]

    target_threshold = max(1, avg_daily_sales * 7)  # target label

    # ✅ Add product_id as input feature
    features.append([product_id, avg_daily_sales, last_7_days, last_30_days, current_stock])
    targets.append(target_threshold)

X = pd.DataFrame(features, columns=['product_id', 'avg_daily_sales', 'last_7_days', 'last_30_days', 'current_stock'])
y = np.array(targets)

# Train model
model = DecisionTreeRegressor()
model.fit(X, y)

# Save model
joblib.dump(model, 'model/restock_threshold_model.pkl')
print('✅ Model trained with product_id as feature.')