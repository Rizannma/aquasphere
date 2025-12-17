/**
 * Navbar Component Loader for Login/Registration/Verify Pages
 * Loads the navbar_logreg.html and initializes dark mode only
 * (No user data, cart, orders, or notifications - these pages are for non-logged-in users)
 */

// Load navbar HTML (async, non-blocking)
async function loadNavbar() {
    const navbarContainer = document.getElementById('navbar-container');
    if (!navbarContainer) {
        console.error('Navbar container not found');
        return;
    }
    
    try {
        const resp = await fetch('navbar_logreg.html', { cache: 'no-cache' });
        if (!resp.ok) throw new Error(`Failed to load navbar: ${resp.status}`);
        const html = await resp.text();
        navbarContainer.innerHTML = html;
        
        initializeDarkMode();
        window.dispatchEvent(new Event('navbarLoaded'));
    } catch (error) {
        console.error('Error loading navbar:', error);
    }
}

// Dark Mode Functionality
function initializeDarkMode() {
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    
    if (!themeToggle || !themeIcon) return;
    
    // Load dark mode preference from localStorage
    const savedTheme = localStorage.getItem('darkMode');
    if (savedTheme === 'true') {
        document.body.classList.add('dark');
        themeIcon.textContent = '☀️';
    } else {
        themeIcon.textContent = '🌙';
    }
    
    // Toggle dark mode
    themeToggle.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        document.body.classList.toggle('dark');
        const isDark = document.body.classList.contains('dark');
        themeIcon.textContent = isDark ? '☀️' : '🌙';
        // Save preference to localStorage
        localStorage.setItem('darkMode', isDark ? 'true' : 'false');
    });
}

// Initialize dark mode on page load
function initDarkModeOnLoad() {
    const savedTheme = localStorage.getItem('darkMode');
    if (savedTheme === 'true') {
        document.body.classList.add('dark');
    }
}

// Initialize dark mode immediately (before DOM ready)
initDarkModeOnLoad();


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

