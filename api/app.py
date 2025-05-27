from flask import Flask, request, jsonify
import joblib
import numpy as np
import pandas as pd
import warnings

app = Flask(__name__)
model = joblib.load('model/restock_threshold_model.pkl')

@app.route('/predict_threshold', methods=['POST'])
def predict_threshold():
    data = request.get_json()
    
    # Wrap input as DataFrame to match trained feature names
    input_data = pd.DataFrame([{
        'product_id': data['product_id'],
        'avg_daily_sales': data['avg_daily_sales'],
        'last_7_days': data['last_7_days'],
        'last_30_days': data['last_30_days'],
        'current_stock': data['current_stock']
    }])

    try:
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            predicted_threshold = model.predict(input_data)
        return jsonify({'predicted_threshold': float(predicted_threshold[0])})
    except Exception as e:
        print(f"Prediction error: {e}")
        return jsonify({'error': 'Prediction failed'}), 500

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=False)
