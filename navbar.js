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
        
        // Load all badges immediately (no delays) - similar to dashboard.html and cart.html
        (async () => {
            // Load cart count immediately
            await updateCartCount();
            
            // Load order count immediately
            updateOrderCount();
            
            // Load notifications immediately (badge only, fast display)
            loadNotificationBadgeFast();
            
            // Load full notifications in background (for dropdown)
            loadNotifications();
        })();

        // Refresh notifications when dropdown is opened
        document.addEventListener('shown.bs.dropdown', (event) => {
            if (event.target && event.target.id === 'navNotifications') {
                loadNotifications(true);
        }
        });
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
    
    // Badges are now loaded immediately in loadNavbar() - no delays needed here
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
        // Retry immediately if element doesn't exist yet (navbar still loading)
        // Use requestAnimationFrame for smooth retry without blocking
        requestAnimationFrame(() => {
            if (document.getElementById('ordersCount')) {
                updateOrderCount();
            } else {
                // Fallback: retry after a very short delay if still not found
                setTimeout(updateOrderCount, 50);
            }
        });
        return;
    }
    
    // FAST PATH: Use cached orders when available (e.g., My Orders page just fetched)
    // The cache should already be filtered (no delivered/cancelled orders)
    if (Array.isArray(window.__ordersCache)) {
        // Filter out delivered and cancelled orders to match My Orders page behavior
        const filteredOrders = window.__ordersCache.filter(o => {
            const status = (o.status || '').toLowerCase();
            return status !== 'cancelled' && status !== 'delivered';
        });
        const orderCount = filteredOrders.length;
        ordersCountEl.textContent = orderCount;
        ordersCountEl.style.display = orderCount > 0 ? 'flex' : 'none';
        // Still fetch in background to sync, but don't wait for it
        fetchOrdersInBackground();
        return;
    }
    
    // FAST PATH: Try localStorage cache first (instant display)
    try {
        const cachedCount = localStorage.getItem('ordersCount');
        const cacheTimestamp = localStorage.getItem('ordersCountTimestamp');
        const now = Date.now();
        // Use cache if it's less than 30 seconds old
        if (cachedCount !== null && cacheTimestamp && (now - parseInt(cacheTimestamp)) < 30000) {
            const orderCount = parseInt(cachedCount, 10);
            ordersCountEl.textContent = orderCount;
            ordersCountEl.style.display = orderCount > 0 ? 'flex' : 'none';
            // Fetch in background to sync, but don't wait for it
            fetchOrdersInBackground();
            return;
        }
    } catch (e) {
        // Ignore localStorage errors
    }
    
    // SLOW PATH: Fetch orders from API (only if no cache available)
    fetchOrdersInBackground();
}

// Fetch orders in background and update cache
function fetchOrdersInBackground() {
    fetch('api/get_orders.php?limit=1000')
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
                // Filter out delivered and cancelled orders (same as My Orders page)
                const filteredOrders = data.orders.filter(o => {
                    const status = (o.status || '').toLowerCase();
                    return status !== 'cancelled' && status !== 'delivered';
                });
                
                const orderCount = filteredOrders.length;
                
                // Cache the count in localStorage for fast loading next time
                try {
                    localStorage.setItem('ordersCount', String(orderCount));
                    localStorage.setItem('ordersCountTimestamp', String(Date.now()));
                } catch (e) {
                    // Ignore localStorage errors
                }
                
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

// Make notification functions available globally
window.loadNotifications = loadNotifications;
window.clearNotificationBadge = clearNotificationBadge;
window.paginateNotifications = paginateNotifications;

// ---------------- Notifications ----------------
let __notifData = [];
let __notifPage = 1;
const __notifPageSize = 4;
const NOTIF_CLEARED_KEY = 'notifClearedAt';
const NOTIF_CLEARED_COOKIE = 'notifClearedAtCookie';

function setNotifCleared(ts) {
    try {
        localStorage.setItem(NOTIF_CLEARED_KEY, String(ts));
    } catch (_) {}
    try {
        document.cookie = `${NOTIF_CLEARED_COOKIE}=${ts}; path=/; max-age=${60 * 60 * 24 * 30}`;
    } catch (_) {}
}

function getNotifCleared() {
    let ts = parseInt(localStorage.getItem(NOTIF_CLEARED_KEY) || '0', 10) || 0;
    try {
        const cookie = document.cookie.split(';').map(c => c.trim()).find(c => c.startsWith(`${NOTIF_CLEARED_COOKIE}=`));
        if (cookie) {
            const val = parseInt(cookie.split('=')[1] || '0', 10) || 0;
            if (val > ts) ts = val;
        }
    } catch (_) {}
    return ts;
}

function clearNotificationBadge() {
    const badge = document.getElementById('notificationCount');
    if (badge) {
        badge.style.display = 'none';
        badge.textContent = '0';
    }
    
    // Clear cache when user manually clears notifications
    try {
        localStorage.setItem('notificationCount', '0');
        localStorage.setItem('notificationCountTimestamp', String(Date.now()));
    } catch (e) {
        // Ignore localStorage errors
    }
    
    __notifData = [];
    __notifPage = 1;
    // Clear saved page from localStorage
    try {
        localStorage.removeItem('notificationPage');
    } catch (e) {
        // Ignore localStorage errors
    }
    renderNotificationPage();
    setNotifCleared(Date.now());
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
    if (status === 'delivered') {
        return {
            title: 'Delivered',
            desc: 'Your order has been successfully delivered.',
            color: 'linear-gradient(135deg, #10b981, #059669)',
            icon: 'fas fa-check-circle'
        };
    }
    if (status === 'cancellation_requested') {
        const isCod = payment === 'cod';
        return {
            title: 'Cancellation Requested',
            desc: isCod
                ? 'Your cancellation request is being reviewed by our admin. Please wait for approval.'
                : 'Your cancellation request is being reviewed by our admin. Please wait for approval.',
            color: 'linear-gradient(135deg, #fbbf24, #f59e0b)',
            icon: 'fas fa-clock'
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

// Fast badge-only loading (for instant display)
function loadNotificationBadgeFast() {
    const badge = document.getElementById('notificationCount');
    if (!badge) {
        // Retry immediately if element doesn't exist yet
        requestAnimationFrame(() => {
            if (document.getElementById('notificationCount')) {
                loadNotificationBadgeFast();
            } else {
                setTimeout(loadNotificationBadgeFast, 50);
            }
        });
        return;
    }

    // FAST PATH: Try localStorage cache first (instant display)
    try {
        const cachedCount = localStorage.getItem('notificationCount');
        const cacheTimestamp = localStorage.getItem('notificationCountTimestamp');
        const now = Date.now();
        // Use cache if it's less than 30 seconds old
        if (cachedCount !== null && cacheTimestamp && (now - parseInt(cacheTimestamp)) < 30000) {
            const notifCount = parseInt(cachedCount, 10);
            badge.textContent = notifCount;
            badge.style.display = notifCount > 0 ? 'flex' : 'none';
            return; // Badge updated, full load will happen in background
        }
    } catch (e) {
        // Ignore localStorage errors
    }
    
    // If no cache, badge will be updated when loadNotifications() completes
    // For now, ensure badge is visible if it should be (will be updated by API call)
}

function loadNotifications(force = false) {
    const badge = document.getElementById('notificationCount');
    const list = document.getElementById('notificationList');
    const wrapper = document.getElementById('navNotificationsWrapper');
    if (!list || !wrapper) {
        // Retry immediately if elements don't exist yet (navbar still loading)
        requestAnimationFrame(() => {
            if (document.getElementById('notificationList') && document.getElementById('navNotificationsWrapper')) {
                loadNotifications(force);
            } else {
                // Fallback: retry after a very short delay if still not found
                setTimeout(() => loadNotifications(force), 50);
            }
        });
        return;
    }

    // FAST PATH: Try localStorage cache first (instant display) - only if not forcing refresh
    if (!force) {
        try {
            const cachedCount = localStorage.getItem('notificationCount');
            const cacheTimestamp = localStorage.getItem('notificationCountTimestamp');
            const now = Date.now();
            // Use cache if it's less than 30 seconds old
            if (cachedCount !== null && cacheTimestamp && (now - parseInt(cacheTimestamp)) < 30000) {
                const notifCount = parseInt(cachedCount, 10);
                if (badge) {
                    badge.textContent = notifCount;
                    badge.style.display = notifCount > 0 ? 'flex' : 'none';
                }
                // Fetch in background to sync, but don't wait for it
                fetchNotificationsInBackground();
                return;
            }
        } catch (e) {
            // Ignore localStorage errors
        }
    }

    // SLOW PATH: Fetch notifications from API
    fetchNotificationsInBackground();
}

// Fetch notifications in background and update cache
function fetchNotificationsInBackground() {
    const badge = document.getElementById('notificationCount');
    const list = document.getElementById('notificationList');
    const wrapper = document.getElementById('navNotificationsWrapper');
    
    fetch('api/get_notifications.php?limit=200')
        .then(resp => {
            if (!resp.ok) {
                if (resp.status === 401) {
                    if (wrapper) wrapper.style.display = 'none';
                    return null;
                }
                throw new Error('Failed to fetch notifications');
            }
            return resp.json();
        })
        .then(data => {
            if (!data) return;

            if (!data.success || !Array.isArray(data.notifications)) {
                if (list) {
                    list.innerHTML = `
                        <div class="notification-empty">
                            <i class="fas fa-inbox"></i>
                            <p>No notifications yet.</p>
                        </div>`;
                }
                
                // Cache the count in localStorage for fast loading next time
                try {
                    localStorage.setItem('notificationCount', '0');
                    localStorage.setItem('notificationCountTimestamp', String(Date.now()));
                } catch (e) {
                    // Ignore localStorage errors
                }
                
                if (badge) {
                    badge.style.display = 'none';
                    badge.textContent = '0';
                }
                return;
            }

            const clearedAt = getNotifCleared();
            const notifications = [];
            data.notifications.forEach(row => {
                const msg = getNotificationMessage({
                    status: row.status,
                    payment_method: row.payment_method
                });
                if (msg) {
                    const ts = parseToManilaDate(row.created_at || '').getTime() || 0;
                    if (clearedAt && ts <= clearedAt) return;
                    notifications.push({
                        ...msg,
                        orderId: row.order_id,
                        when: row.created_at
                    });
                }
            });

            __notifData = notifications;
            
            // Restore saved page from localStorage, or default to 1
            let savedPage = 1;
            try {
                const saved = localStorage.getItem('notificationPage');
                if (saved) {
                    savedPage = parseInt(saved, 10);
                    if (isNaN(savedPage) || savedPage < 1) {
                        savedPage = 1;
                    }
                }
            } catch (e) {
                // Ignore localStorage errors
            }
            
            // Validate saved page against total pages
            const totalPages = Math.max(1, Math.ceil(notifications.length / __notifPageSize));
            __notifPage = Math.min(savedPage, totalPages);
            
            if (list) {
                renderNotificationPage();
            }

            // Always update badge, even if list doesn't exist yet
            // Get badge fresh each time to ensure we have the latest element
            const badgeEl = document.getElementById('notificationCount');
            if (badgeEl) {
                // When user cleared locally, honor filtered count (post-clear)
                // Otherwise prefer API total if provided.
                const apiTotal = data.pagination?.total;
                const filteredCount = notifications.length;
                const totalCount = clearedAt > 0
                    ? filteredCount
                    : (typeof apiTotal === 'number' && apiTotal >= 0 ? apiTotal : filteredCount);
                
                // Cache the count in localStorage for fast loading next time
                try {
                    localStorage.setItem('notificationCount', String(totalCount));
                    localStorage.setItem('notificationCountTimestamp', String(Date.now()));
                } catch (e) {
                    // Ignore localStorage errors
                }
                
                badgeEl.textContent = totalCount;
                // Always set display explicitly - use 'flex' when count > 0, 'none' when 0
                badgeEl.style.display = totalCount > 0 ? 'flex' : 'none';
                
                // Force visibility - ensure badge is shown
                if (totalCount > 0) {
                    badgeEl.style.visibility = 'visible';
                    badgeEl.style.opacity = '1';
                }
            }
        })
        .catch(err => {
            console.error('Notifications error:', err);
        });
}

function paginateNotifications(delta) {
    const totalPages = Math.max(1, Math.ceil((__notifData || []).length / __notifPageSize));
    const nextPage = Math.min(totalPages, Math.max(1, __notifPage + delta));
    if (nextPage === __notifPage) return;
    __notifPage = nextPage;
    // Store current page in localStorage for persistence
    try {
        localStorage.setItem('notificationPage', String(__notifPage));
    } catch (e) {
        // Ignore localStorage errors
    }
    renderNotificationPage();
}

function renderNotificationPage() {
    const list = document.getElementById('notificationList');
    const pageInfo = document.getElementById('notifPageInfo');
    const prevBtn = document.getElementById('notifPrevBtn');
    const nextBtn = document.getElementById('notifNextBtn');
    if (!list) return;

    if (!__notifData || __notifData.length === 0) {
        list.innerHTML = `
            <div class="notification-empty">
                <i class="fas fa-inbox"></i>
                <p>No notifications yet.</p>
            </div>`;
        if (pageInfo) pageInfo.textContent = 'Page 1 of 1';
        if (prevBtn) prevBtn.disabled = true;
        if (nextBtn) nextBtn.disabled = true;
        return;
    }

    const totalPages = Math.max(1, Math.ceil(__notifData.length / __notifPageSize));
    __notifPage = Math.min(__notifPage, totalPages);
    // Save current page to localStorage
    try {
        localStorage.setItem('notificationPage', String(__notifPage));
    } catch (e) {
        // Ignore localStorage errors
    }
    const start = (__notifPage - 1) * __notifPageSize;
    const current = __notifData.slice(start, start + __notifPageSize);

    list.innerHTML = current.map(n => `
        <div class="notification-item">
            <div class="notification-icon" style="background:${n.color};">
                <i class="${n.icon}"></i>
            </div>
            <div class="notification-content">
                <div>
                    <div class="notification-title">${n.title}</div>
                    <p class="notification-desc">${n.desc}</p>
                    <div class="notification-meta">Order #${n.orderId}${formatNotifTime(n.when)}</div>
                </div>
            </div>
        </div>
    `).join('');

    if (pageInfo) pageInfo.textContent = `Page ${__notifPage} of ${totalPages}`;
    if (prevBtn) prevBtn.disabled = __notifPage <= 1;
    if (nextBtn) nextBtn.disabled = __notifPage >= totalPages;
}

function formatNotifTime(ts) {
    if (!ts) return '';
    const parsed = parseToManilaDate(ts);
    const formatted = parsed.toLocaleString('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
    return ` • ${formatted}`;
}

function parseToManilaDate(ts) {
    // Normalize to treat timestamp as UTC then render Asia/Manila
    if (typeof ts === 'number') return new Date(ts);
    let iso = ts;
    if (ts && typeof ts === 'string' && !ts.includes('T')) {
        iso = ts.replace(' ', 'T') + 'Z';
    }
    return new Date(iso);
}

// Prevent dropdown from closing when paginating/clearing
document.addEventListener('click', (e) => {
    const target = e.target;
    if (!target) return;
    if (target.id === 'notifPrevBtn' || target.id === 'notifNextBtn' || target.id === 'notifClearBtn' || target.closest('#notifPrevBtn') || target.closest('#notifNextBtn') || target.closest('#notifClearBtn')) {
        e.preventDefault();
        e.stopPropagation();
    }
});

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

