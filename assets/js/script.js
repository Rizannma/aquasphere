// Custom JavaScript for Landing Page

// Smooth scroll for navigation links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Navbar background on scroll
window.addEventListener('scroll', function() {
    const navbar = document.querySelector('.navbar');
    if (window.scrollY > 50) {
        navbar.style.backgroundColor = 'rgba(13, 110, 253, 0.95)';
        navbar.style.backdropFilter = 'blur(10px)';
    } else {
        navbar.style.backgroundColor = '';
        navbar.style.backdropFilter = '';
    }
});

// Form submission handler
document.querySelector('form')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const submitButton = this.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    
    // Show loading state
    submitButton.innerHTML = '<span class="loading"></span> Sending...';
    submitButton.disabled = true;
    
    // Simulate form submission (replace with actual API call)
    setTimeout(() => {
        submitButton.innerHTML = '<i class="bi bi-check-circle"></i> Message Sent!';
        submitButton.classList.remove('btn-primary');
        submitButton.classList.add('btn-success');
        
        // Reset form
        this.reset();
        
        // Reset button after 3 seconds
        setTimeout(() => {
            submitButton.innerHTML = originalText;
            submitButton.classList.remove('btn-success');
            submitButton.classList.add('btn-primary');
            submitButton.disabled = false;
        }, 3000);
    }, 1500);
});

// ---------- Client-side input guard to block obvious XSS/SQLi patterns ----------
// UX-friendly: scrubs dangerous patterns as user types/pastes; server-side still enforces.
(function attachInputGuards() {
    const BAD_PATTERNS = [
        /<\s*script/gi,
        /javascript:/gi,
        /onerror\s*=/gi,
        /onload\s*=/gi,
        /onclick\s*=/gi,
        /onmouseover\s*=/gi,
        /--/g,
        /\/\*/g,
        /\*\//g,
        /'\s*or\s*1=1/gi,
        /"\s*or\s*1=1/gi
    ];
    function scrub(value) {
        let v = value;
        BAD_PATTERNS.forEach(re => {
            v = v.replace(re, '');
        });
        return v;
    }
    function guardInput(el) {
        if (!el) return;
        const handler = (e) => {
            const before = el.value;
            const after = scrub(before);
            if (after !== before) {
                const start = el.selectionStart;
                const end = el.selectionEnd;
                el.value = after;
                const delta = before.length - after.length;
                const pos = Math.max(0, (start ?? after.length) - delta);
                el.setSelectionRange(pos, pos);
                e.preventDefault();
            }
        };
        el.addEventListener('input', handler);
        el.addEventListener('paste', (e) => {
            setTimeout(() => handler(e), 0);
        });
    }
    document.addEventListener('DOMContentLoaded', () => {
        const fields = document.querySelectorAll('input[type="text"], input[type="email"], input[type="search"], input[type="tel"], textarea');
        fields.forEach(guardInput);
    });
})();

// Fade in animation on scroll
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver(function(entries) {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('fade-in');
            observer.unobserve(entry.target);
        }
    });
}, observerOptions);

// Observe all cards and sections
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.card');
    const sections = document.querySelectorAll('section');
    
    cards.forEach(card => observer.observe(card));
    sections.forEach(section => observer.observe(section));
});

// Active navigation link highlighting
window.addEventListener('scroll', function() {
    const sections = document.querySelectorAll('section[id]');
    const navLinks = document.querySelectorAll('.nav-link');
    
    let current = '';
    sections.forEach(section => {
        const sectionTop = section.offsetTop;
        const sectionHeight = section.clientHeight;
        if (window.scrollY >= sectionTop - 200) {
            current = section.getAttribute('id');
        }
    });
    
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href') === `#${current}`) {
            link.classList.add('active');
        }
    });
});

// Add active class styling
const style = document.createElement('style');
style.textContent = `
    .nav-link.active {
        color: #fff !important;
        font-weight: 600;
        border-bottom: 2px solid #fff;
    }
`;
document.head.appendChild(style);

