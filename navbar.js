/**
 * Shared Navbar Component Loader
 * Loads the navbar HTML and initializes it
 */

// Load navbar HTML synchronously to prevent lag
function loadNavbar() {
    const navbarContainer = document.getElementById('navbar-container');
    if (!navbarContainer) {
        console.error('Navbar container not found');
        return;
    }
    
    // Use synchronous XMLHttpRequest for immediate loading (no lag)
    try {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', 'navbar.html', false); // false = synchronous
        xhr.send(null);
        
        if (xhr.status === 200) {
            navbarContainer.innerHTML = xhr.responseText;
            
            // Initialize navbar immediately after loading
            initializeNavbar();
            
            // Dispatch event to notify that navbar is loaded
            window.dispatchEvent(new Event('navbarLoaded'));
        } else {
            console.error('Failed to load navbar:', xhr.status);
        }
    } catch (error) {
        console.error('Error loading navbar:', error);
        // Fallback: try async fetch
        fetch('navbar.html')
            .then(response => response.text())
            .then(html => {
                navbarContainer.innerHTML = html;
                initializeNavbar();
                
                // Dispatch event to notify that navbar is loaded
                window.dispatchEvent(new Event('navbarLoaded'));
            })
            .catch(err => console.error('Fallback navbar load failed:', err));
    }
}

// Initialize navbar functionality
function initializeNavbar() {
    // Navbar styling is handled by navbar.css - no JavaScript manipulation needed
    
    // Set active nav item based on current page
    const currentPage = window.location.pathname.split('/').pop() || 'dashboard.html';
    
    // Remove active class from all nav items
    document.querySelectorAll('.nav-link').forEach(link => {
        link.classList.remove('active');
    });
    
    // Add active class to current page
    if (currentPage === 'dashboard.html' || currentPage === 'index.html') {
        const homeLink = document.getElementById('navHome');
        if (homeLink) homeLink.classList.add('active');
    } else if (currentPage === 'cart.html') {
        const cartLink = document.getElementById('navCart');
        if (cartLink) cartLink.classList.add('active');
    } else if (currentPage === 'orders.html') {
        const ordersLink = document.getElementById('navOrders');
        if (ordersLink) ordersLink.classList.add('active');
    }
    
    // Load user data
    loadUserData();
    
    // Update cart count
    updateCartCount();
    
    // Update order count
    updateOrderCount();
}

// Load user data for navbar
function loadUserData() {
    // Try to fetch from server first, then fallback to localStorage
    fetch('api/get_current_user.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to fetch user data');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.user) {
                const userData = data.user;
                // Save to localStorage
                localStorage.setItem('loggedInUser', JSON.stringify(userData));
                localStorage.setItem('userData', JSON.stringify(userData));
                
                const usernameDisplay = document.getElementById('usernameDisplay');
                if (usernameDisplay) {
                    usernameDisplay.textContent = userData.username || 'User';
                }
            } else {
                // Fallback to localStorage
                const userData = JSON.parse(localStorage.getItem('loggedInUser')) || 
                               JSON.parse(localStorage.getItem('userData')) || 
                               JSON.parse(sessionStorage.getItem('userData')) || {};
                const usernameDisplay = document.getElementById('usernameDisplay');
                if (usernameDisplay) {
                    usernameDisplay.textContent = userData.username || 'User';
                }
            }
        })
        .catch(error => {
            console.error('Error loading user data:', error);
            // Fallback to localStorage
            const userData = JSON.parse(localStorage.getItem('loggedInUser')) || 
                           JSON.parse(localStorage.getItem('userData')) || 
                           JSON.parse(sessionStorage.getItem('userData')) || {};
            const usernameDisplay = document.getElementById('usernameDisplay');
            if (usernameDisplay) {
                usernameDisplay.textContent = userData.username || 'User';
            }
        });
}

// Update cart count in navbar
function updateCartCount() {
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    const totalItems = cart.reduce((sum, item) => sum + (item.quantity || 0), 0);
    const cartCountEl = document.getElementById('cartCount');
    if (cartCountEl) {
        cartCountEl.textContent = totalItems;
        // Hide badge when count is 0, only show when there are items
        cartCountEl.style.display = totalItems > 0 ? 'flex' : 'none';
    }
}

// Make updateCartCount available globally so pages can call it
window.updateCartCount = updateCartCount;

// Update order count in navbar
function updateOrderCount() {
    // Check if user is logged in
    const userData = JSON.parse(localStorage.getItem('loggedInUser')) || 
                     JSON.parse(localStorage.getItem('userData')) || 
                     JSON.parse(sessionStorage.getItem('userData')) || null;
    
    if (!userData || !userData.user_id) {
        // Hide badge if not logged in
        const ordersCountEl = document.getElementById('ordersCount');
        if (ordersCountEl) {
            ordersCountEl.style.display = 'none';
        }
        return;
    }
    
    // Fetch orders from API
    fetch('api/get_orders.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to fetch orders');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.orders) {
                const orderCount = data.orders.length;
                const ordersCountEl = document.getElementById('ordersCount');
                if (ordersCountEl) {
                    ordersCountEl.textContent = orderCount;
                    // Hide badge when count is 0, only show when there are orders
                    ordersCountEl.style.display = orderCount > 0 ? 'flex' : 'none';
                }
            } else {
                // Hide badge on error
                const ordersCountEl = document.getElementById('ordersCount');
                if (ordersCountEl) {
                    ordersCountEl.style.display = 'none';
                }
            }
        })
        .catch(error => {
            console.error('Error fetching order count:', error);
            // Hide badge on error
            const ordersCountEl = document.getElementById('ordersCount');
            if (ordersCountEl) {
                ordersCountEl.style.display = 'none';
            }
        });
}

// Make updateOrderCount available globally so pages can call it
window.updateOrderCount = updateOrderCount;

// Load navbar immediately (before DOMContentLoaded to prevent lag)
// This ensures navbar appears instantly without delay
if (document.readyState === 'loading') {
    // If still loading, wait for DOM but load immediately
    document.addEventListener('DOMContentLoaded', function() {
        loadNavbar();
    });
    // Also try to load immediately if container exists
    if (document.getElementById('navbar-container')) {
        loadNavbar();
    }
} else {
    // DOM already loaded, load immediately
    loadNavbar();
}

// Also try loading immediately on script execution
(function() {
    const container = document.getElementById('navbar-container');
    if (container && !container.innerHTML.trim()) {
        loadNavbar();
    }
})();

