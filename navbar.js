/**
 * Shared Navbar Component Loader
 * Loads the navbar HTML and initializes it
 */

// Load navbar HTML
function loadNavbar() {
    const navbarContainer = document.getElementById('navbar-container');
    if (!navbarContainer) {
        console.error('Navbar container not found');
        return;
    }
    
    fetch('navbar.html')
        .then(response => response.text())
        .then(html => {
            navbarContainer.innerHTML = html;
            
            // Initialize navbar after it's loaded
            initializeNavbar();
        })
        .catch(error => {
            console.error('Error loading navbar:', error);
        });
}

// Initialize navbar functionality
function initializeNavbar() {
    // Fix navbar size
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        navbar.style.setProperty('height', '70px', 'important');
        navbar.style.setProperty('min-height', '70px', 'important');
        navbar.style.setProperty('max-height', '70px', 'important');
        navbar.style.setProperty('padding', '0', 'important');
        navbar.style.setProperty('line-height', '70px', 'important');
        navbar.style.setProperty('position', 'fixed', 'important');
        navbar.style.setProperty('top', '0', 'important');
        navbar.style.setProperty('transition', 'none', 'important');
    }
    
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
        cartCountEl.style.display = totalItems > 0 ? 'block' : 'none';
    }
}

// Load navbar when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadNavbar);
} else {
    loadNavbar();
}

