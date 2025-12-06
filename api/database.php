<?php
/**
 * Database Module for AquaSphere
 * Supports both PostgreSQL (for hosting) and SQLite (for local development)
 */

// Detect if PostgreSQL is available
$GLOBALS['use_postgres'] = !empty($_ENV['DATABASE_URL']) || 
               (!empty($_ENV['PGHOST']) && !empty($_ENV['PGDATABASE']) && 
                !empty($_ENV['PGUSER']) && !empty($_ENV['PGPASSWORD']));

$GLOBALS['db_path'] = $_ENV['DATABASE_PATH'] ?? 'aquasphere.db';

if ($GLOBALS['use_postgres']) {
    // PostgreSQL connection
    function get_db_connection() {
        if (!empty($_ENV['DATABASE_URL'])) {
            $conn = pg_connect($_ENV['DATABASE_URL']);
        } else {
            $conn = pg_connect(
                "host=" . $_ENV['PGHOST'] . 
                " port=" . ($_ENV['PGPORT'] ?? 5432) . 
                " dbname=" . $_ENV['PGDATABASE'] . 
                " user=" . $_ENV['PGUSER'] . 
                " password=" . $_ENV['PGPASSWORD']
            );
        }
        if (!$conn) {
            die("PostgreSQL connection failed");
        }
        return $conn;
    }
    
    function execute_sql($conn, $query, $params = null) {
        if ($params) {
            // Replace ? with $1, $2, etc. for PostgreSQL
            $param_count = 1;
            $pg_query = preg_replace_callback('/\?/', function() use (&$param_count) {
                return '$' . $param_count++;
            }, $query);
            return pg_query_params($conn, $pg_query, $params);
        } else {
            return pg_query($conn, $query);
        }
    }
    
    function get_id_type() {
        return "SERIAL PRIMARY KEY";
    }
    
    function get_text_type() {
        return "VARCHAR(255)";
    }
    
    function get_integer_type() {
        return "INTEGER";
    }
    
    function fetch_assoc($result) {
        return pg_fetch_assoc($result);
    }
    
    function last_insert_id($conn, $table) {
        $result = pg_query($conn, "SELECT lastval()");
        $row = pg_fetch_row($result);
        return $row[0];
    }
    
    function close_connection($conn) {
        pg_close($conn);
    }
} else {
    // SQLite connection
    function get_db_connection() {
        global $db_path;
        $conn = new SQLite3($GLOBALS['db_path']);
        if (!$conn) {
            die("SQLite connection failed: " . $conn->lastErrorMsg());
        }
        return $conn;
    }
    
    function execute_sql($conn, $query, $params = null) {
        $stmt = $conn->prepare($query);
        if ($params) {
            foreach ($params as $index => $param) {
                $stmt->bindValue($index + 1, $param);
            }
        }
        return $stmt->execute();
    }
    
    function get_id_type() {
        return "INTEGER PRIMARY KEY AUTOINCREMENT";
    }
    
    function get_text_type() {
        return "TEXT";
    }
    
    function get_integer_type() {
        return "INTEGER";
    }
    
    function fetch_assoc($result) {
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    function last_insert_id($conn, $table) {
        return $conn->lastInsertRowID();
    }
    
    function close_connection($conn) {
        $conn->close();
    }
}

/**
 * Initialize the database with necessary tables
 */
function init_db() {
    $conn = get_db_connection();
    
    // Create users table
    $query = "
    CREATE TABLE IF NOT EXISTS users (
        id " . get_id_type() . ",
        username " . get_text_type() . " UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        email " . get_text_type() . " UNIQUE NOT NULL,
        first_name " . get_text_type() . ",
        last_name " . get_text_type() . ",
        gender " . get_text_type() . ",
        date_of_birth DATE,
        is_admin " . get_integer_type() . " DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_login TIMESTAMP
    )
    ";
    
    execute_sql($conn, $query);
    
    // Create system_settings table
    $query = "
    CREATE TABLE IF NOT EXISTS system_settings (
        id " . get_id_type() . ",
        setting_key " . get_text_type() . " UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_by " . get_integer_type() . " DEFAULT NULL
    )
    ";
    
    execute_sql($conn, $query);
    
    // Create otp_verification table
    $query = "
    CREATE TABLE IF NOT EXISTS otp_verification (
        id " . get_id_type() . ",
        email " . get_text_type() . " NOT NULL,
        otp_code " . get_text_type() . " NOT NULL,
        username " . get_text_type() . " NOT NULL,
        password_hash TEXT NOT NULL,
        first_name " . get_text_type() . ",
        last_name " . get_text_type() . ",
        gender " . get_text_type() . ",
        date_of_birth DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        is_verified " . get_integer_type() . " DEFAULT 0
    )
    ";
    
    execute_sql($conn, $query);
    
    // Create orders table for water delivery system
    // Note: FOREIGN KEY constraints are handled differently for PostgreSQL vs SQLite
    if ($GLOBALS['use_postgres']) {
        $query = "
        CREATE TABLE IF NOT EXISTS orders (
            id " . get_id_type() . ",
            user_id " . get_integer_type() . " NOT NULL,
            order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            delivery_date DATE,
            delivery_time TIME,
            delivery_address TEXT,
            total_amount DECIMAL(10,2) NOT NULL,
            payment_method " . get_text_type() . ",
            status " . get_text_type() . " DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
        ";
        execute_sql($conn, $query);
        
        // Add foreign key constraint separately for PostgreSQL (if it doesn't exist)
        if ($GLOBALS['use_postgres']) {
            $check_query = "SELECT 1 FROM pg_constraint WHERE conname = 'fk_orders_user_id'";
            $check_result = execute_sql($conn, $check_query);
            $exists = pg_fetch_assoc($check_result);
            if (!$exists) {
                $query = "ALTER TABLE orders ADD CONSTRAINT fk_orders_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE";
                execute_sql($conn, $query);
            }
        }
    } else {
        $query = "
        CREATE TABLE IF NOT EXISTS orders (
            id " . get_id_type() . ",
            user_id " . get_integer_type() . " NOT NULL,
            order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            delivery_date DATE,
            delivery_time TIME,
            delivery_address TEXT,
            total_amount DECIMAL(10,2) NOT NULL,
            payment_method " . get_text_type() . ",
            status " . get_text_type() . " DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
        ";
        execute_sql($conn, $query);
    }
    
    // Create order_items table
    if ($GLOBALS['use_postgres']) {
        $query = "
        CREATE TABLE IF NOT EXISTS order_items (
            id " . get_id_type() . ",
            order_id " . get_integer_type() . " NOT NULL,
            product_name " . get_text_type() . " NOT NULL,
            product_price DECIMAL(10,2) NOT NULL,
            quantity " . get_integer_type() . " NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL
        )
        ";
        execute_sql($conn, $query);
        
        // Add foreign key constraint separately for PostgreSQL (if it doesn't exist)
        if ($GLOBALS['use_postgres']) {
            $check_query = "SELECT 1 FROM pg_constraint WHERE conname = 'fk_order_items_order_id'";
            $check_result = execute_sql($conn, $check_query);
            $exists = pg_fetch_assoc($check_result);
            if (!$exists) {
                $query = "ALTER TABLE order_items ADD CONSTRAINT fk_order_items_order_id FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE";
                execute_sql($conn, $query);
            }
        }
    } else {
        $query = "
        CREATE TABLE IF NOT EXISTS order_items (
            id " . get_id_type() . ",
            order_id " . get_integer_type() . " NOT NULL,
            product_name " . get_text_type() . " NOT NULL,
            product_price DECIMAL(10,2) NOT NULL,
            quantity " . get_integer_type() . " NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id)
        )
        ";
        execute_sql($conn, $query);
    }
    
    close_connection($conn);
}

/**
 * Get user by username
 */
function get_user_by_username($username) {
    $conn = get_db_connection();
    $query = "SELECT * FROM users WHERE username = ?";
    $result = execute_sql($conn, $query, [$username]);
    
    if ($GLOBALS['use_postgres']) {
        $user = pg_fetch_assoc($result);
    } else {
        $user = $result->fetchArray(SQLITE3_ASSOC);
    }
    
    close_connection($conn);
    return $user ? $user : null;
}

/**
 * Get user by email
 */
function get_user_by_email($email) {
    $conn = get_db_connection();
    $query = "SELECT * FROM users WHERE email = ?";
    $result = execute_sql($conn, $query, [$email]);
    
    if ($GLOBALS['use_postgres']) {
        $user = pg_fetch_assoc($result);
    } else {
        $user = $result->fetchArray(SQLITE3_ASSOC);
    }
    
    close_connection($conn);
    return $user ? $user : null;
}

/**
 * Create a new user
 */
function create_user($username, $password, $email, $first_name, $last_name, $gender, $date_of_birth) {
    $conn = get_db_connection();
    
    // Check if username or email already exists
    $existing_user = get_user_by_username($username);
    if ($existing_user) {
        close_connection($conn);
        return ['success' => false, 'message' => 'Username already exists.'];
    }
    
    $existing_email = get_user_by_email($email);
    if ($existing_email) {
        close_connection($conn);
        return ['success' => false, 'message' => 'Email already exists.'];
    }
    
    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert user
    $query = "
        INSERT INTO users 
        (username, password_hash, email, first_name, last_name, gender, date_of_birth, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ";
    
    $result = execute_sql($conn, $query, [
        $username, $password_hash, $email, $first_name, $last_name, $gender, $date_of_birth
    ]);
    
    if ($result) {
        $user_id = last_insert_id($conn, 'users');
        close_connection($conn);
        return ['success' => true, 'user_id' => $user_id];
    } else {
        close_connection($conn);
        return ['success' => false, 'message' => 'Failed to create user.'];
    }
}

/**
 * Get system setting value
 */
function get_system_setting($key, $default = null) {
    $conn = get_db_connection();
    $query = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
    $result = execute_sql($conn, $query, [$key]);
    
    if ($GLOBALS['use_postgres']) {
        $row = pg_fetch_assoc($result);
        $value = $row ? $row['setting_value'] : null;
    } else {
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $value = $row ? $row['setting_value'] : null;
    }
    
    // Debug logging
    if (in_array($key, ['brevo_api_key', 'brevo_sender_email', 'enable_email_notifications'])) {
        error_log("get_system_setting('$key') - found: " . ($value !== null ? "YES (length: " . strlen($value) . ")" : "NO") . ", returning: " . ($value !== null ? $value : ($default !== null ? "default: $default" : "null")));
    }
    
    close_connection($conn);
    return $value !== null ? $value : $default;
}

/**
 * Update or insert system setting
 */
function update_system_setting($key, $value, $user_id = null) {
    $conn = get_db_connection();
    
    // Ensure table exists
    init_db();
    
    // Check if setting exists
    $query = "SELECT id FROM system_settings WHERE setting_key = ?";
    $result = execute_sql($conn, $query, [$key]);
    
    if ($GLOBALS['use_postgres']) {
        $exists = pg_fetch_assoc($result);
    } else {
        $exists = $result->fetchArray(SQLITE3_ASSOC);
    }
    
    if ($exists) {
        // Update existing setting
        $query = "UPDATE system_settings SET setting_value = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE setting_key = ?";
        $result = execute_sql($conn, $query, [$value, $user_id, $key]);
        
        if ($GLOBALS['use_postgres']) {
            $success = $result !== false && pg_affected_rows($result) > 0;
        } else {
            $success = $result !== false && $conn->changes() > 0;
        }
    } else {
        // Insert new setting
        $query = "INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)";
        $result = execute_sql($conn, $query, [$key, $value, $user_id]);
        $success = $result !== false;
    }
    
    // Verify the save was successful - use same connection
    if ($success) {
        $verify_query = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
        $verify_result = execute_sql($conn, $verify_query, [$key]);
        
        if ($GLOBALS['use_postgres']) {
            $verify_row = pg_fetch_assoc($verify_result);
            $saved_value = $verify_row ? $verify_row['setting_value'] : null;
        } else {
            $verify_row = $verify_result->fetchArray(SQLITE3_ASSOC);
            $saved_value = $verify_row ? $verify_row['setting_value'] : null;
        }
        
        if ($saved_value !== $value) {
            error_log("Warning: Setting $key was saved but verification failed. Expected: " . (in_array($key, ['brevo_api_key']) ? '***HIDDEN***' : $value) . ", Got: " . ($saved_value ?? 'null'));
            $success = false;
        } else {
            // Commit the transaction if using PostgreSQL
            if ($GLOBALS['use_postgres']) {
                // PostgreSQL auto-commits by default, but let's make sure
                pg_query($conn, "COMMIT");
            }
        }
    }
    
    close_connection($conn);
    return $success;
}

/**
 * Get all system settings
 */
function get_all_system_settings() {
    $conn = get_db_connection();
    $query = "SELECT setting_key, setting_value FROM system_settings";
    $result = execute_sql($conn, $query);
    
    $settings = [];
    if ($GLOBALS['use_postgres']) {
        while ($row = pg_fetch_assoc($result)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } else {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    close_connection($conn);
    return $settings;
}

/**
 * Store OTP verification data
 */
function store_otp_verification($email, $otp_code, $username, $password_hash, $first_name, $last_name, $gender, $date_of_birth, $expires_at) {
    $conn = get_db_connection();
    
    // Delete any existing OTP for this email
    $deleteQuery = "DELETE FROM otp_verification WHERE email = ?";
    execute_sql($conn, $deleteQuery, [$email]);
    
    // Insert new OTP
    $query = "
        INSERT INTO otp_verification 
        (email, otp_code, username, password_hash, first_name, last_name, gender, date_of_birth, expires_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ";
    
    $result = execute_sql($conn, $query, [
        $email, $otp_code, $username, $password_hash, $first_name, $last_name, $gender, $date_of_birth, $expires_at
    ]);
    
    close_connection($conn);
    return $result ? true : false;
}

/**
 * Verify OTP code and get user data
 */
function verify_otp_code($email, $otp_code) {
    $conn = get_db_connection();
    
    $query = "
        SELECT * FROM otp_verification 
        WHERE email = ? AND otp_code = ? AND is_verified = 0 AND expires_at > CURRENT_TIMESTAMP
        ORDER BY created_at DESC
        LIMIT 1
    ";
    
    $result = execute_sql($conn, $query, [$email, $otp_code]);
    
    if ($GLOBALS['use_postgres']) {
        $otp_data = pg_fetch_assoc($result);
    } else {
        $otp_data = $result->fetchArray(SQLITE3_ASSOC);
    }
    
    if ($otp_data) {
        // Mark as verified
        $updateQuery = "UPDATE otp_verification SET is_verified = 1 WHERE id = ?";
        execute_sql($conn, $updateQuery, [$otp_data['id']]);
        
        // Return user data
        $user_data = [
            'username' => $otp_data['username'],
            'password_hash' => $otp_data['password_hash'],
            'first_name' => $otp_data['first_name'],
            'last_name' => $otp_data['last_name'],
            'gender' => $otp_data['gender'],
            'date_of_birth' => $otp_data['date_of_birth']
        ];
        
        close_connection($conn);
        return $user_data;
    }
    
    close_connection($conn);
    return null;
}

/**
 * Get pending OTP data by email
 */
function get_pending_otp($email) {
    $conn = get_db_connection();
    
    $query = "
        SELECT * FROM otp_verification 
        WHERE email = ? AND is_verified = 0 AND expires_at > CURRENT_TIMESTAMP
        ORDER BY created_at DESC
        LIMIT 1
    ";
    
    $result = execute_sql($conn, $query, [$email]);
    
    if ($GLOBALS['use_postgres']) {
        $otp_data = pg_fetch_assoc($result);
    } else {
        $otp_data = $result->fetchArray(SQLITE3_ASSOC);
    }
    
    close_connection($conn);
    return $otp_data ? $otp_data : null;
}

/**
 * Update OTP code (for resend)
 */
function update_otp_code($email, $otp_code, $expires_at) {
    $conn = get_db_connection();
    
    $query = "
        UPDATE otp_verification 
        SET otp_code = ?, expires_at = ?, created_at = CURRENT_TIMESTAMP
        WHERE email = ? AND is_verified = 0
    ";
    
    $result = execute_sql($conn, $query, [$otp_code, $expires_at, $email]);
    
    if ($GLOBALS['use_postgres']) {
        $affected = pg_affected_rows($result);
    } else {
        $affected = $conn->changes();
    }
    
    close_connection($conn);
    return $affected > 0;
}

/**
 * Clean up expired OTP records
 */
function cleanup_expired_otp() {
    $conn = get_db_connection();
    
    $query = "DELETE FROM otp_verification WHERE expires_at < CURRENT_TIMESTAMP OR is_verified = 1";
    execute_sql($conn, $query);
    
    close_connection($conn);
}

// Initialize database if it doesn't exist (SQLite only)
if (!$GLOBALS['use_postgres'] && !file_exists($GLOBALS['db_path'])) {
    init_db();
}
?>

