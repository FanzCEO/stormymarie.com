/**
 * STORMY WORLD - Social Platform JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    initWorldNav();
    initFeedTabs();
    initModals();
    initComposer();
    initPolls();
    initLikeButtons();
});

/**
 * Section Navigation
 */
function initWorldNav() {
    const navItems = document.querySelectorAll('.world__nav-item');
    const sections = document.querySelectorAll('.world__section');

    navItems.forEach(function(item) {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            var sectionId = this.dataset.section;
            if (!sectionId) return;

            // Update nav
            navItems.forEach(function(n) { n.classList.remove('active'); });
            this.classList.add('active');

            // Update sections
            sections.forEach(function(s) { s.classList.remove('active'); });
            var target = document.getElementById('section-' + sectionId);
            if (target) target.classList.add('active');
        });
    });
}

/**
 * Feed Tabs
 */
function initFeedTabs() {
    var tabs = document.querySelectorAll('.world__tab');
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            tabs.forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
        });
    });
}

/**
 * Modals (Auth, Tip)
 */
function initModals() {
    // Login/Signup toggle
    var showLogin = document.getElementById('showLogin');
    var showSignup = document.getElementById('showSignup');
    var loginModal = document.getElementById('loginModal');
    var signupModal = document.getElementById('signupModal');
    var tipModal = document.getElementById('tipModal');

    if (showLogin) {
        showLogin.addEventListener('click', function(e) {
            e.preventDefault();
            if (signupModal) signupModal.classList.add('hidden');
            if (loginModal) loginModal.classList.remove('hidden');
        });
    }

    if (showSignup) {
        showSignup.addEventListener('click', function(e) {
            e.preventDefault();
            if (loginModal) loginModal.classList.add('hidden');
            if (signupModal) signupModal.classList.remove('hidden');
        });
    }

    // Login button triggers
    var loginBtn = document.getElementById('loginBtn');
    var joinBtn = document.getElementById('joinBtn');

    if (loginBtn && loginModal) {
        loginBtn.addEventListener('click', function() {
            loginModal.classList.remove('hidden');
        });
    }

    if (joinBtn && signupModal) {
        joinBtn.addEventListener('click', function() {
            signupModal.classList.remove('hidden');
        });
    }

    // Close modals
    document.querySelectorAll('.world__modal-close').forEach(function(btn) {
        btn.addEventListener('click', function() {
            this.closest('.world__modal').classList.add('hidden');
        });
    });

    document.querySelectorAll('.world__modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function() {
            this.closest('.world__modal').classList.add('hidden');
        });
    });

    // Tip buttons
    document.querySelectorAll('.world__action-btn--tip').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (tipModal) tipModal.classList.remove('hidden');
        });
    });

    // Tip amount selection
    document.querySelectorAll('.world__tip-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.world__tip-btn').forEach(function(b) {
                b.classList.remove('world__tip-btn--popular');
            });
            this.classList.add('world__tip-btn--popular');
        });
    });
}

/**
 * Post Composer
 */
function initComposer() {
    var submitPost = document.getElementById('submitPost');
    var postInput = document.getElementById('postInput');
    var newPostBtn = document.getElementById('newPostBtn');

    if (submitPost && postInput) {
        submitPost.addEventListener('click', function() {
            var text = postInput.value.trim();
            if (!text) return;

            // Create post element
            var post = createPostElement({
                author: 'You',
                handle: '@user',
                time: 'Just now',
                content: text,
                verified: false
            });

            var feed = document.getElementById('feedContainer');
            if (feed) {
                var firstPost = feed.querySelector('.world__post');
                if (firstPost) {
                    feed.insertBefore(post, firstPost);
                } else {
                    feed.appendChild(post);
                }
            }

            postInput.value = '';
        });
    }

    if (newPostBtn && postInput) {
        newPostBtn.addEventListener('click', function() {
            postInput.focus();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
}

function createPostElement(data) {
    var article = document.createElement('article');
    article.className = 'world__post';
    article.innerHTML =
        '<div class="world__post-header">' +
            '<div class="world__post-avatar">' +
                '<img src="https://via.placeholder.com/48" alt="' + data.author + '">' +
            '</div>' +
            '<div class="world__post-meta">' +
                '<div class="world__post-author">' +
                    '<strong>' + data.author + '</strong>' +
                    '<span class="world__handle">' + data.handle + '</span>' +
                '</div>' +
                '<span class="world__post-time">' + data.time + '</span>' +
            '</div>' +
        '</div>' +
        '<div class="world__post-content"><p>' + escapeHtml(data.content) + '</p></div>' +
        '<div class="world__post-actions">' +
            '<button class="world__action-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg><span>0</span></button>' +
            '<button class="world__action-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg><span>0</span></button>' +
        '</div>';

    // Add like functionality to new post
    var likeBtn = article.querySelector('.world__action-btn');
    if (likeBtn) {
        likeBtn.addEventListener('click', function() {
            this.classList.toggle('world__action-btn--liked');
            var count = this.querySelector('span');
            if (count) {
                var n = parseInt(count.textContent) || 0;
                count.textContent = this.classList.contains('world__action-btn--liked') ? n + 1 : Math.max(0, n - 1);
            }
        });
    }

    return article;
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Poll Voting
 */
function initPolls() {
    document.querySelectorAll('.world__poll-option').forEach(function(option) {
        option.addEventListener('click', function() {
            var poll = this.closest('.world__poll');
            if (!poll || poll.dataset.voted) return;

            poll.dataset.voted = 'true';
            this.style.borderColor = 'rgba(255, 16, 240, 0.5)';
            this.style.background = 'rgba(255, 16, 240, 0.05)';
        });
    });
}

/**
 * Like Buttons
 */
function initLikeButtons() {
    document.querySelectorAll('.world__action-btn').forEach(function(btn) {
        // Only first action button in each post (heart/like)
        var actions = btn.closest('.world__post-actions');
        if (!actions) return;
        if (btn !== actions.querySelector('.world__action-btn')) return;

        btn.addEventListener('click', function() {
            this.classList.toggle('world__action-btn--liked');
            var countEl = this.querySelector('span');
            if (countEl) {
                var text = countEl.textContent.trim();
                var num = parseFloat(text.replace('k', '')) || 0;
                if (text.includes('k')) num *= 1000;
                if (this.classList.contains('world__action-btn--liked')) {
                    num += 1;
                } else {
                    num = Math.max(0, num - 1);
                }
                if (num >= 1000) {
                    countEl.textContent = (num / 1000).toFixed(1).replace('.0', '') + 'k';
                } else {
                    countEl.textContent = num;
                }
            }
        });
    });
}
