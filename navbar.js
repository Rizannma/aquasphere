/**
 * Shared Navbar Component Loader
 * Loads the navbar HTML and initializes it
 */

// Load navbar HTML (async, non-blocking)
async function loadNavbar() {
    const navbarContainer = document.getElementById('navbar-container');
    if (!navbarContainer) {
        console.error('Navbar container not found');
        return;
    }

    // Preload logo for faster paint
    (function preloadLogo() {
        const head = document.head || document.getElementsByTagName('head')[0];
        if (!head) return;
        if (document.getElementById('preload-systemlogo')) return;
        const link = document.createElement('link');
        link.id = 'preload-systemlogo';
        link.rel = 'preload';
        link.as = 'image';
        link.href = 'systemlogo.png';
        link.fetchPriority = 'high';
        head.appendChild(link);
    })();
    
    try {
        const resp = await fetch('navbar.html', { cache: 'no-cache' });
        if (!resp.ok) throw new Error(`Failed to load navbar: ${resp.status}`);
        const html = await resp.text();
        navbarContainer.innerHTML = html;
        
        initializeNavbar();
        window.dispatchEvent(new Event('navbarLoaded'));
        setTimeout(updateOrderCount, 50);
    } catch (error) {
        console.error('Error loading navbar:', error);
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
    
    // Use cached orders when available (e.g., My Orders page just fetched)
    if (Array.isArray(window.__ordersCache)) {
        const orderCount = window.__ordersCache.length;
        ordersCountEl.textContent = orderCount;
        ordersCountEl.style.display = orderCount > 0 ? 'flex' : 'none';
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
                }
            } else {
                // No orders or invalid response
                const badgeEl = document.getElementById('ordersCount');
                if (badgeEl) {
                    badgeEl.style.display = 'none';
                }
            }
        })
        .catch(error => {
            console.error('Error fetching order count:', error);
            // Don't hide badge on network error - might be temporary
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

