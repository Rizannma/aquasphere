// Lightweight loader for shared public navbar (index, login, registration)

async function loadPublicNavbar() {
  const container = document.getElementById('public-navbar-container');
  if (!container) {
    return;
  }

  try {
    const resp = await fetch('public-navbar.html', { cache: 'no-cache' });
    if (!resp.ok) {
      throw new Error('Failed to load public navbar: ' + resp.status);
    }
    const html = await resp.text();
    container.innerHTML = html;

    // Fire event so pages can hook behaviour (active states, etc.)
    window.dispatchEvent(new Event('publicNavbarLoaded'));
  } catch (err) {
    console.error(err);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', loadPublicNavbar);
} else {
  loadPublicNavbar();
}


