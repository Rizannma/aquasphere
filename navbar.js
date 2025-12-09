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

    try {
        const resp = await fetch('navbar.html', { cache: 'no-cache' });
        if (!resp.ok) throw new Error(`Failed to load navbar: ${resp.status}`);
        const html = await resp.text();
        navbarContainer.innerHTML = html;
        
        initializeNavbar();
        window.dispatchEvent(new Event('navbarLoaded'));
        setTimeout(updateOrderCount, 50);
        setTimeout(loadNotifications, 120);
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

    // Load notifications (after navbar render)
    setTimeout(loadNotifications, 150);
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
async function updateCartCount() {
    // Use UserState if available, otherwise fallback to localStorage
    let cart = [];
    if (typeof UserState !== 'undefined') {
        await UserState.loadState();
        cart = UserState.getCart();
    } else {
        cart = JSON.parse(localStorage.getItem('cart') || '[]');
    }
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
    // Fetch without pagination limit to get total count, or use pagination.total
    fetch('api/get_orders.php?limit=1')
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
            
            if (data.success) {
                // Use pagination.total if available (total count), otherwise use orders array length
                const orderCount = (data.pagination && data.pagination.total !== undefined) 
                    ? data.pagination.total 
                    : (Array.isArray(data.orders) ? data.orders.length : 0);
                
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

// ---------------- Notifications ----------------
function clearNotificationBadge() {
    const badge = document.getElementById('notificationCount');
    if (badge) {
        badge.style.display = 'none';
        badge.textContent = '0';
    }
}

function getNotificationMessage(order) {
    const status = (order.status || '').toLowerCase();
    const payment = (order.payment_method || order.paymentMethod || '').toLowerCase();

    if (status === 'pending') {
        return {
            title: 'Order Placed',
            desc: 'Your order has been placed! Kindly wait for admin approval.',
            color: 'linear-gradient(135deg, #0ea5e9, #0369a1)',
            icon: 'fas fa-check-circle'
        };
    }
    if (status === 'preparing') {
        return {
            title: 'Preparing',
            desc: 'Your order has been approved and is now being prepared.',
            color: 'linear-gradient(135deg, #6366f1, #4338ca)',
            icon: 'fas fa-box'
        };
    }
    if (status === 'shipped') {
        return {
            title: 'Shipped',
            desc: 'Your order has been shipped and is now in transit. Please await our next update regarding delivery.',
            color: 'linear-gradient(135deg, #06b6d4, #0ea5e9)',
            icon: 'fas fa-shipping-fast'
        };
    }
    if (status === 'out_for_delivery') {
        const isCod = payment === 'cod';
        return {
            title: 'Out for Delivery',
            desc: isCod
                ? 'Your order is now out for delivery. Please ensure the corresponding payment is prepared.'
                : 'Your order is now out for delivery and will reach you soon.',
            color: 'linear-gradient(135deg, #22c55e, #16a34a)',
            icon: 'fas fa-truck'
        };
    }
    if (status === 'cancelled') {
        const isCod = payment === 'cod';
        return {
            title: 'Order Cancelled',
            desc: isCod
                ? 'The cancellation of your order has been completed successfully.'
                : 'Your order has been successfully cancelled. Your payment has been refunded to your GCash account.',
            color: 'linear-gradient(135deg, #ef4444, #dc2626)',
            icon: 'fas fa-ban'
        };
    }
    return null;
}

function loadNotifications() {
    const badge = document.getElementById('notificationCount');
    const list = document.getElementById('notificationList');
    const wrapper = document.getElementById('navNotificationsWrapper');
    if (!list || !wrapper) return;

    fetch('api/get_orders.php?limit=20')
        .then(resp => {
            if (!resp.ok) {
                if (resp.status === 401) {
                    wrapper.style.display = 'none';
                    return null;
                }
                throw new Error('Failed to fetch notifications');
            }
            return resp.json();
        })
        .then(data => {
            if (!data) return;

            if (!data.success || !Array.isArray(data.orders)) {
                list.innerHTML = `
                    <div class="notification-empty">
                        <i class="fas fa-inbox"></i>
                        <p>No notifications yet.</p>
                    </div>`;
                clearNotificationBadge();
                return;
            }

            const orders = data.orders.slice().sort((a, b) => {
                const da = new Date(a.order_date || a.created_at || 0).getTime();
                const db = new Date(b.order_date || b.created_at || 0).getTime();
                return db - da;
            });

            const notifications = [];
            orders.forEach(order => {
                const msg = getNotificationMessage(order);
                if (msg) {
                    notifications.push({
                        ...msg,
                        orderId: order.id,
                        when: order.order_date || order.created_at || ''
                    });
                }
            });

            if (notifications.length === 0) {
                list.innerHTML = `
                    <div class="notification-empty">
                        <i class="fas fa-inbox"></i>
                        <p>No notifications yet.</p>
                    </div>`;
                clearNotificationBadge();
                return;
            }

            const latest = notifications.slice(0, 6);
            list.innerHTML = latest.map(n => `
                <div class="notification-item">
                    <div class="notification-icon" style="background:${n.color};">
                        <i class="${n.icon}"></i>
                    </div>
                    <div class="notification-content">
                        <div class="notification-title">${n.title}</div>
                        <p class="notification-desc">${n.desc}</p>
                        <div class="notification-meta">Order #${n.orderId}${n.when ? ' • ' + new Date(n.when).toLocaleString() : ''}</div>
                    </div>
                </div>
            `).join('');

            if (badge) {
                badge.textContent = notifications.length;
                badge.style.display = notifications.length > 0 ? 'flex' : 'none';
            }
        })
        .catch(err => {
            console.error('Notifications error:', err);
        });
}

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

