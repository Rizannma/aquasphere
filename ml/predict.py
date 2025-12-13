"""
Predict Delivery Time and Shipping Fee
Real-time prediction script that can be called from PHP
"""

import sys
import json
import joblib
import numpy as np
import pandas as pd
from sklearn.preprocessing import LabelEncoder
import os
from datetime import datetime, timedelta

# Delivery hub location (San Pablo City)
HUB_LATITUDE = 14.0703
HUB_LONGITUDE = 121.3253

# Shipping fee calculation constants
BASE_FEE = 50.0  # Base shipping fee in PHP
RATE_PER_MINUTE = 0.5  # PHP per minute of delivery time

def haversine_distance(lat1, lon1, lat2, lon2):
    """Calculate distance between two points in kilometers"""
    from math import radians, cos, sin, asin, sqrt
    
    lat1, lon1, lat2, lon2 = map(radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = sin(dlat/2)**2 + cos(lat1) * cos(lat2) * sin(dlon/2)**2
    c = 2 * asin(sqrt(a))
    r = 6371  # Earth radius in km
    return c * r

def load_model(model_dir='models'):
    """Load the trained model and encoders"""
    model_file = os.path.join(model_dir, 'delivery_time_model.joblib')
    encoders_file = os.path.join(model_dir, 'label_encoders.joblib')
    metadata_file = os.path.join(model_dir, 'model_metadata.json')
    
    if not os.path.exists(model_file):
        raise FileNotFoundError(f"Model file not found: {model_file}. Please train the model first.")
    
    model = joblib.load(model_file)
    label_encoders = joblib.load(encoders_file)
    
    with open(metadata_file, 'r') as f:
        metadata = json.load(f)
    
    return model, label_encoders, metadata

def encode_categorical_features(features, label_encoders):
    """Encode categorical features using saved label encoders"""
    encoded_features = features.copy()
    
    for col, encoder in label_encoders.items():
        if col in encoded_features:
            value = encoded_features[col]
            try:
                # Try to transform the value
                if value in encoder.classes_:
                    encoded_features[col + '_encoded'] = encoder.transform([value])[0]
                else:
                    # If value not seen during training, use most common class
                    encoded_features[col + '_encoded'] = encoder.transform([encoder.classes_[0]])[0]
            except:
                # Fallback to first class if encoding fails
                encoded_features[col + '_encoded'] = 0
    
    return encoded_features

def predict_delivery_time(latitude, longitude, municipality, barangay, postal_code, 
                          time_of_order, day_of_week, order_size, model_dir='models'):
    """
    Predict delivery time in minutes
    
    Args:
        latitude: Delivery latitude
        longitude: Delivery longitude
        municipality: Municipality name
        barangay: Barangay name
        postal_code: Postal code
        time_of_order: Hour of order (0-23)
        day_of_week: Day of week (0=Monday, 6=Sunday)
        order_size: Number of water bottles
        model_dir: Directory containing trained model
    
    Returns:
        Predicted delivery time in minutes
    """
    try:
        # Load model
        model, label_encoders, metadata = load_model(model_dir)
        
        # Calculate distance from hub
        distance_km = haversine_distance(HUB_LATITUDE, HUB_LONGITUDE, latitude, longitude)
        
        # Prepare features
        features = {
            'distance_km': distance_km,
            'latitude': latitude,
            'longitude': longitude,
            'municipality': municipality,
            'barangay': barangay,
            'postal_code': postal_code,
            'time_of_order': int(time_of_order),
            'day_of_week': int(day_of_week),
            'order_size': int(order_size)
        }
        
        # Encode categorical features
        encoded_features = encode_categorical_features(features, label_encoders)
        
        # Get feature columns in correct order
        feature_cols = metadata['feature_columns']
        
        # Create feature array
        X = np.array([[encoded_features.get(col, 0) for col in feature_cols]])
        
        # Predict
        delivery_time_minutes = model.predict(X)[0]
        
        # Ensure minimum delivery time
        delivery_time_minutes = max(20, delivery_time_minutes)
        
        return round(delivery_time_minutes, 2)
    
    except Exception as e:
        # Fallback calculation if model fails
        distance_km = haversine_distance(HUB_LATITUDE, HUB_LONGITUDE, latitude, longitude)
        base_time = 15
        minutes_per_km = 2.5
        delivery_time_minutes = base_time + (distance_km * minutes_per_km) + (order_size * 0.5)
        return round(max(20, delivery_time_minutes), 2)

def calculate_shipping_fee(delivery_time_minutes):
    """
    Calculate shipping fee based on delivery time
    
    Args:
        delivery_time_minutes: Predicted delivery time in minutes
    
    Returns:
        Shipping fee in PHP
    """
    shipping_fee = BASE_FEE + (delivery_time_minutes * RATE_PER_MINUTE)
    return round(shipping_fee, 2)

def calculate_delivery_date_range(delivery_time_minutes, order_datetime=None):
    """
    Calculate delivery date range from predicted delivery time in minutes
    Supports same-day, next-day, and multi-day delivery based on predicted time
    
    Args:
        delivery_time_minutes: Predicted delivery time in minutes
        order_datetime: Datetime object for when the order was placed (default: current time)
    
    Returns:
        Dictionary with 'start_date', 'end_date', 'start_date_formatted', 'end_date_formatted', 'date_range'
    """
    if order_datetime is None:
        order_datetime = datetime.now()
    
    # Convert minutes to hours
    hours = delivery_time_minutes / 60
    order_hour = order_datetime.hour
    
    # Determine delivery days based on predicted time and order time
    # Same-day delivery: < 4 hours AND order placed before 2 PM (14:00)
    # Next-day delivery: < 8 hours OR order placed after 2 PM
    # Multi-day delivery: >= 8 hours
    
    if hours < 4 and order_hour < 14:
        # Same-day delivery possible (order early, short distance)
        processing_days = 0
        delivery_days = 0  # Same day
        delivery_window_days = 1  # Range: today - tomorrow
    elif hours < 8 or order_hour >= 14:
        # Next-day delivery (short distance or late order)
        processing_days = 0 if order_hour < 14 else 1  # If late order, need 1 day processing
        delivery_days = 1  # Next day
        delivery_window_days = 1  # Range: tomorrow - day after
    else:
        # Multi-day delivery (longer distance)
        # Calculate days needed based on delivery hours
        # Assuming 8 hours of delivery work per day
        delivery_days = max(1, int(hours / 8))
        processing_days = 1  # Always 1 day processing for longer deliveries
        delivery_window_days = 2  # Range of 2 days for longer deliveries
    
    # Calculate start date
    start_date = order_datetime + timedelta(days=processing_days + delivery_days)
    
    # Calculate end date (start date + delivery window)
    end_date = start_date + timedelta(days=delivery_window_days)
    
    # Format dates
    start_formatted = start_date.strftime('%b %d')
    end_formatted = end_date.strftime('%b %d')
    
    # Create date range string
    if start_date.year != end_date.year or start_date.month != end_date.month:
        # Different months or years
        date_range = f"{start_date.strftime('%b %d')} - {end_date.strftime('%b %d, %Y')}"
    else:
        # Same month
        date_range = f"{start_formatted} - {end_formatted}"
    
    return {
        'start_date': start_date.isoformat(),
        'end_date': end_date.isoformat(),
        'start_date_formatted': start_formatted,
        'end_date_formatted': end_formatted,
        'date_range': date_range
    }

def main():
    """Main function for command-line usage"""
    if len(sys.argv) < 2:
        # Read from stdin (for PHP calls)
        try:
            input_data = json.loads(sys.stdin.read())
        except:
            print(json.dumps({
                'success': False,
                'error': 'Invalid JSON input'
            }))
            sys.exit(1)
    else:
        # Read from command line argument
        try:
            input_data = json.loads(sys.argv[1])
        except:
            print(json.dumps({
                'success': False,
                'error': 'Invalid JSON argument'
            }))
            sys.exit(1)
    
    # Extract input parameters
    latitude = float(input_data.get('latitude'))
    longitude = float(input_data.get('longitude'))
    municipality = input_data.get('municipality', '')
    barangay = input_data.get('barangay', '')
    postal_code = input_data.get('postal_code', '')
    time_of_order = input_data.get('time_of_order', 12)  # Default to noon
    day_of_week = input_data.get('day_of_week', 0)  # Default to Monday
    order_size = int(input_data.get('order_size', 1))
    model_dir = input_data.get('model_dir', 'models')
    
    # Get order datetime if provided (for accurate date calculation)
    order_datetime_str = input_data.get('order_datetime')
    order_datetime = None
    if order_datetime_str:
        try:
            order_datetime = datetime.fromisoformat(order_datetime_str.replace('Z', '+00:00'))
        except:
            order_datetime = datetime.now()
    else:
        order_datetime = datetime.now()
    
    try:
        # Predict delivery time
        delivery_time_minutes = predict_delivery_time(
            latitude, longitude, municipality, barangay, postal_code,
            time_of_order, day_of_week, order_size, model_dir
        )
        
        # Calculate shipping fee
        shipping_fee = calculate_shipping_fee(delivery_time_minutes)
        
        # Calculate delivery date range
        date_range_info = calculate_delivery_date_range(delivery_time_minutes, order_datetime)
        
        # Return results
        result = {
            'success': True,
            'delivery_time_minutes': delivery_time_minutes,
            'shipping_fee': shipping_fee,
            'delivery_time_hours': round(delivery_time_minutes / 60, 2),
            'delivery_date_range': date_range_info['date_range'],
            'delivery_start_date': date_range_info['start_date'],
            'delivery_end_date': date_range_info['end_date'],
            'delivery_start_date_formatted': date_range_info['start_date_formatted'],
            'delivery_end_date_formatted': date_range_info['end_date_formatted']
        }
        
        print(json.dumps(result))
    
    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': str(e)
        }))
        sys.exit(1)

if __name__ == '__main__':
    main()


