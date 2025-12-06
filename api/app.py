"""
Python Flask API for AquaSphere
Additional backend functionality
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import os
import psycopg2
from psycopg2.extras import RealDictCursor
import sqlite3
from datetime import datetime

app = Flask(__name__)
CORS(app)

# Database configuration
def get_db_connection():
    """Get database connection (PostgreSQL or SQLite)"""
    # Check for PostgreSQL connection
    database_url = os.environ.get('DATABASE_URL')
    
    if database_url:
        # PostgreSQL connection
        return psycopg2.connect(database_url)
    else:
        # SQLite connection (local development)
        db_path = os.environ.get('DATABASE_PATH', 'aquasphere.db')
        return sqlite3.connect(db_path)

def is_postgres():
    """Check if using PostgreSQL"""
    return os.environ.get('DATABASE_URL') is not None

@app.route('/api/python/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    try:
        conn = get_db_connection()
        conn.close()
        db_status = 'connected'
        db_type = 'PostgreSQL' if is_postgres() else 'SQLite'
    except Exception as e:
        db_status = 'error'
        db_type = 'unknown'
    
    return jsonify({
        'status': 'ok',
        'service': 'AquaSphere Python API',
        'database': db_status,
        'db_type': db_type,
        'timestamp': datetime.now().isoformat()
    })

@app.route('/api/python/orders', methods=['GET'])
def get_orders():
    """Get all orders for a user"""
    try:
        user_id = request.args.get('user_id', type=int)
        if not user_id:
            return jsonify({'success': False, 'message': 'user_id is required'}), 400
        
        conn = get_db_connection()
        
        if is_postgres():
            cursor = conn.cursor(cursor_factory=RealDictCursor)
            cursor.execute("""
                SELECT o.*, 
                       json_agg(json_build_object(
                           'product_name', oi.product_name,
                           'quantity', oi.quantity,
                           'price', oi.product_price
                       )) as items
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE o.user_id = %s
                GROUP BY o.id
                ORDER BY o.order_date DESC
            """, (user_id,))
            orders = cursor.fetchall()
            orders = [dict(row) for row in orders]
        else:
            cursor = conn.cursor()
            cursor.execute("""
                SELECT o.*, 
                       GROUP_CONCAT(oi.product_name || ':' || oi.quantity) as items
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE o.user_id = ?
                GROUP BY o.id
                ORDER BY o.order_date DESC
            """, (user_id,))
            rows = cursor.fetchall()
            orders = []
            for row in rows:
                orders.append({
                    'id': row[0],
                    'user_id': row[1],
                    'order_date': row[2],
                    'delivery_date': row[3],
                    'delivery_time': row[4],
                    'delivery_address': row[5],
                    'total_amount': float(row[6]),
                    'payment_method': row[7],
                    'status': row[8],
                    'items': row[9]
                })
        
        conn.close()
        return jsonify({'success': True, 'orders': orders})
    
    except Exception as e:
        return jsonify({'success': False, 'message': str(e)}), 500

@app.route('/api/python/orders', methods=['POST'])
def create_order():
    """Create a new order"""
    try:
        data = request.get_json()
        user_id = data.get('user_id')
        items = data.get('items', [])
        delivery_date = data.get('delivery_date')
        delivery_time = data.get('delivery_time')
        delivery_address = data.get('delivery_address')
        payment_method = data.get('payment_method', 'COD')
        
        if not user_id or not items:
            return jsonify({'success': False, 'message': 'user_id and items are required'}), 400
        
        # Calculate total
        total = sum(item['price'] * item['quantity'] for item in items)
        
        conn = get_db_connection()
        cursor = conn.cursor()
        
        if is_postgres():
            cursor.execute("""
                INSERT INTO orders (user_id, delivery_date, delivery_time, delivery_address, 
                                  total_amount, payment_method, status)
                VALUES (%s, %s, %s, %s, %s, %s, 'pending')
                RETURNING id
            """, (user_id, delivery_date, delivery_time, delivery_address, total, payment_method))
            order_id = cursor.fetchone()[0]
            
            # Insert order items
            for item in items:
                cursor.execute("""
                    INSERT INTO order_items (order_id, product_name, product_price, quantity, subtotal)
                    VALUES (%s, %s, %s, %s, %s)
                """, (order_id, item['name'], item['price'], item['quantity'], item['price'] * item['quantity']))
        else:
            cursor.execute("""
                INSERT INTO orders (user_id, delivery_date, delivery_time, delivery_address, 
                                  total_amount, payment_method, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            """, (user_id, delivery_date, delivery_time, delivery_address, total, payment_method))
            order_id = cursor.lastrowid
            
            # Insert order items
            for item in items:
                cursor.execute("""
                    INSERT INTO order_items (order_id, product_name, product_price, quantity, subtotal)
                    VALUES (?, ?, ?, ?, ?)
                """, (order_id, item['name'], item['price'], item['quantity'], item['price'] * item['quantity']))
        
        conn.commit()
        conn.close()
        
        return jsonify({'success': True, 'order_id': order_id, 'message': 'Order created successfully'})
    
    except Exception as e:
        return jsonify({'success': False, 'message': str(e)}), 500

@app.route('/api/python/orders/<int:order_id>/status', methods=['PUT'])
def update_order_status():
    """Update order status"""
    try:
        data = request.get_json()
        status = data.get('status')
        
        if not status:
            return jsonify({'success': False, 'message': 'status is required'}), 400
        
        conn = get_db_connection()
        cursor = conn.cursor()
        
        if is_postgres():
            cursor.execute("""
                UPDATE orders 
                SET status = %s, updated_at = CURRENT_TIMESTAMP
                WHERE id = %s
            """, (status, order_id))
        else:
            cursor.execute("""
                UPDATE orders 
                SET status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            """, (status, order_id))
        
        conn.commit()
        conn.close()
        
        return jsonify({'success': True, 'message': 'Order status updated'})
    
    except Exception as e:
        return jsonify({'success': False, 'message': str(e)}), 500

if __name__ == '__main__':
    # Use a different port for Python API (5000) or get from env
    # PHP will use the main PORT env var, Python uses PYTHON_PORT or defaults to 5000
    port = int(os.environ.get('PYTHON_PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=False)

