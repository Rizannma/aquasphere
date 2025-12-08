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
            
            // Update order count after navbar is loaded
            setTimeout(updateOrderCount, 50);
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
                
                // Update order count after navbar is loaded
                setTimeout(updateOrderCount, 50);
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
    
    // Update order count (with delay to ensure navbar is fully rendered)
    setTimeout(updateOrderCount, 100);
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
    // Try to get badge element - retry if not found (navbar might still be loading)
    const ordersCountEl = document.getElementById('ordersCount');
    if (!ordersCountEl) {
        // Retry after a short delay if element doesn't exist yet
        setTimeout(updateOrderCount, 100);
        return;
    }
    
    // Fetch orders from API (API uses session, so no need to check localStorage)
    fetch('api/get_orders.php')
        .then(response => {
            if (!response.ok) {
                // If 401, user is not logged in - hide badge
                if (response.status === 401) {
                    ordersCountEl.style.display = 'none';
                    return null;
                }
                throw new Error('Failed to fetch orders: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (!data) return; // Handled 401 case above
            
            if (data.success && Array.isArray(data.orders)) {
                const orderCount = data.orders.length;
                const badgeEl = document.getElementById('ordersCount');
                if (badgeEl) {
                    badgeEl.textContent = orderCount;
                    // Show badge when count > 0, hide when 0
                    badgeEl.style.display = orderCount > 0 ? 'flex' : 'none';
                    console.log('Order count badge updated:', orderCount, 'orders');
                }
            } else {
                // No orders or invalid response
                const badgeEl = document.getElementById('ordersCount');
                if (badgeEl) {
                    badgeEl.style.display = 'none';
                }
                console.log('No orders found or invalid response:', data);
            }
        })
        .catch(error => {
            console.error('Error fetching order count:', error);
            // Don't hide badge on network error - might be temporary
            // The badge will update on next page load or manual refresh
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

