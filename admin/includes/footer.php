        </div> <!-- End content-wrapper -->
    </div> <!-- End admin-main -->
</div> <!-- End admin-layout -->

<div id="adminAiAssistant" class="admin-ai-assistant">
    <button type="button" id="adminAiToggle" class="admin-ai-toggle" aria-label="فتح المساعد الذكي">
        <svg viewBox="0 0 24 24" width="34" height="34" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: block; margin: auto;">
            <path d="M12 2C12.5 7.5 16.5 11.5 22 12C16.5 12.5 12.5 16.5 12 22C11.5 16.5 7.5 12.5 2 12C7.5 11.5 11.5 7.5 12 2Z" fill="currentColor"/>
        </svg>
    </button>
</div>

<div id="adminAiBackdrop" class="admin-ai-backdrop" hidden></div>

<aside id="adminAiPanel" class="admin-ai-panel" hidden aria-hidden="true">
    <div class="admin-ai-content" id="adminAiContent">
        <div id="adminAiHero" class="admin-ai-hero">
            <div class="ai-sparkle-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2C12.5 7.5 16.5 11.5 22 12C16.5 12.5 12.5 16.5 12 22C11.5 16.5 7.5 12.5 2 12C7.5 11.5 11.5 7.5 12 2Z" fill="url(#sparkle-gradient)"/>
                    <defs>
                        <linearGradient id="sparkle-gradient" x1="2" y1="2" x2="22" y2="22" gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stop-color="#4e6af3"/>
                            <stop offset="50%" stop-color="#9b51e0"/>
                            <stop offset="100%" stop-color="#f24db0"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <p class="ai-hero-text">بعض الأفكار المبتكرة لتطوير لوحة التحكم الخاصة بك.</p>
        </div>

        <div id="adminAiMessages" class="admin-ai-messages"></div>
    </div>

    <form id="adminAiForm" class="admin-ai-form">
        <div class="admin-ai-input-wrapper">
            <textarea id="adminAiInput" rows="1" placeholder="اكتب رسالتك أو استفسارك هنا..." required></textarea>
            
            <div class="ai-input-right">
                <button type="submit" id="adminAiSend" class="ai-send-btn" aria-label="إرسال">
                    <i class="fas fa-arrow-up"></i>
                </button>
            </div>
        </div>
    </form>
</aside>

<style>
.admin-ai-assistant {
    position: fixed;
    left: 0px;
    bottom: -5px;
    z-index: 1201;
    transition: opacity 0.18s ease, transform 0.18s ease;
}

.admin-ai-assistant.is-hidden {
    opacity: 0;
    pointer-events: none;
    transform: scale(0.92);
}

.admin-ai-toggle {
    width: 40px;
    height: 40px;
    border: none;
    border-radius: 0 20px 20px 0;
    background: linear-gradient(to right, #e100ffff, #7f00d4ff );
    opacity: 0.3;
    color: #fff;
    box-shadow: 0 18px 36px rgba(15, 23, 42, 0.22);
    cursor: pointer;
    transition: transform 0.16s ease, box-shadow 0.16s ease;
}

.admin-ai-toggle:hover {
    opacity: 1;
    transform: scale(1.1);
    box-shadow: 0 22px 40px rgba(15, 23, 42, 0.28);
}

html[data-admin-lang="en"] .admin-ai-assistant {
    left: auto;
    right: 0;
}

html[data-admin-lang="en"] .admin-ai-toggle {
    border-radius: 20px 0 0 20px;
}

.admin-ai-backdrop[hidden],
.admin-ai-panel[hidden] {
    display: none !important;
}

.admin-ai-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    z-index: 1198;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.admin-ai-backdrop.is-open {
    opacity: 1;
}

.admin-ai-panel {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: min(430px, 100vw);
    background: #fafafa;
    border-right: 1px solid rgba(203, 213, 225, 0.7);
    box-shadow: 18px 0 50px rgba(15, 23, 42, 0.12);
    z-index: 1200;
    display: flex;
    flex-direction: column;
    transform: translateX(-100%);
    transition: transform 0.22s ease;
}

.admin-ai-panel.is-open {
    transform: translateX(0);
}

html[data-admin-lang="en"] .admin-ai-panel {
    left: auto;
    right: 0;
    border-right: none;
    border-left: 1px solid rgba(203, 213, 225, 0.7);
    box-shadow: -18px 0 50px rgba(15, 23, 42, 0.12);
    transform: translateX(100%);
}

html[data-admin-lang="en"] .admin-ai-panel.is-open {
    transform: translateX(0);
}

.admin-ai-content {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    padding: 0;
    position: relative;
    scroll-behavior: smooth;
}

.admin-ai-hero {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    padding: 40px;
    text-align: center;
    transition: opacity 0.3s ease, min-height 0.4s ease, padding 0.4s ease;
}

.admin-ai-hero.has-messages {
    min-height: 0;
    padding: 10px 20px 0;
    flex-direction: row;
    justify-content: flex-start;
    align-items: center;
    gap: 15px;
}

.admin-ai-hero.has-messages .ai-sparkle-icon {
    width: 44px;
    height: 44px;
    padding: 0;
}

.admin-ai-hero.has-messages .ai-hero-text {
    font-size: 0.9rem;
    margin-top: 0;
    text-align: right;
    opacity: 0.8;
    display: none; /* Hide text on active chat for cleaner look */
}

.ai-sparkle-icon {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 100px;
    height: 100px;
    margin: 0 auto;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

.ai-sparkle-icon svg {
    width: 100%;
    height: 100%;
    filter: drop-shadow(0 15px 25px rgba(155, 81, 224, 0.25));
}

.ai-hero-text {
    margin-top: 35px;
    font-size: 1.05rem;
    color: #1e293b;
    line-height: 1.6;
    font-weight: 500;
    max-width: 450px;
    transition: all 0.3s ease;
}

.admin-ai-messages {
    padding: 0 40px 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.admin-ai-bubble {
    max-width: 85%;
    padding: 16px 20px;
    border-radius: 20px;
    line-height: 1.7;
    font-size: 0.95rem;
    white-space: pre-wrap;
    animation: aiBubbleFadeIn 0.3s ease-out backwards;
}

@keyframes aiBubbleFadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.admin-ai-bubble.assistant {
    align-self: flex-start;
    background: transparent;
    color: #1e293b;
    border-radius: 12px;
}

/* Adjust if overall layout is LTR */
html[dir="ltr"] .admin-ai-bubble.assistant {
    align-self: flex-start;
}

html[dir="rtl"] .admin-ai-bubble.user {
    border-top-left-radius: 20px;
    border-bottom-left-radius: 4px;
}

.admin-ai-bubble.user {
    align-self: flex-end;
    background: #f1f5f9;
    color: #1e293b;
    border-radius: 20px;
    padding: 12px 18px;
}

.admin-ai-bubble.meta {
    align-self: center;
    background: transparent;
    color: #94a3b8;
    font-size: 0.85rem;
    padding: 8px 16px;
    animation: none;
}

.admin-ai-form {
    padding: 10px 20px;
    background: transparent;
}

.admin-ai-input-wrapper {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 22px 10px 10px;
    border-radius: 35px;
    background: #ffffff;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(226, 232, 240, 0.6);
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.admin-ai-input-wrapper:focus-within {
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
    border-color: #cbd5e1;
}

.ai-input-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

.admin-ai-form textarea {
    flex: 1;
    min-height: 24px;
    max-height: 120px;
    resize: none;
    border: none;
    background: transparent;
    color: #1e293b;
    padding: 8px 0;
    font-family: inherit;
    font-size: 0.95rem;
    line-height: 1.5;
}

.admin-ai-form textarea::placeholder {
    color: #94a3b8;
}

.admin-ai-form textarea:focus {
    outline: none;
}

.ai-send-btn {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    border: none;
    background: #0f172a;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.ai-send-btn:hover {
    background: #000000;
    transform: scale(1.05);
}

.ai-send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

@media (max-width: 768px) {
    .admin-ai-panel {
        width: 100vw;
    }
    
    .admin-ai-panel.is-open {
        transform: translateX(0);
    }
    
    .admin-ai-form {
        padding: 0 20px 20px;
    }
    
    .admin-ai-hero {
        padding: 20px;
    }
    
    .admin-ai-messages {
        padding: 0 20px 20px;
    }
}
</style>


<script>
// Admin AI Assistant Widget
(function() {
    const assistantRoot = document.getElementById('adminAiAssistant');
    const toggleBtn = document.getElementById('adminAiToggle');
    const panel = document.getElementById('adminAiPanel');
    const backdrop = document.getElementById('adminAiBackdrop');
    const form = document.getElementById('adminAiForm');
    const input = document.getElementById('adminAiInput');
    const messages = document.getElementById('adminAiMessages');
    const sendBtn = document.getElementById('adminAiSend');
    const contentWrapper = document.getElementById('adminAiContent');
    const hero = document.getElementById('adminAiHero');
    const history = [];
    let isBootstrapped = false;

    if (!assistantRoot || !toggleBtn || !panel || !backdrop || !form || !input || !messages || !sendBtn) {
        return;
    }

    function addMessage(role, text, extraClass = '') {
        const bubble = document.createElement('div');
        bubble.className = `admin-ai-bubble ${role}${extraClass ? ' ' + extraClass : ''}`;
        bubble.textContent = text;
        messages.appendChild(bubble);
        contentWrapper.scrollTop = contentWrapper.scrollHeight;
    }

    function ensureWelcomeMessage() {
        if (isBootstrapped) {
            return;
        }

        // We don't need a welcome message in the new UI, as the hero text serves that purpose.
        isBootstrapped = true;
    }

    function setOpen(isOpen) {
        if (isOpen) {
            backdrop.hidden = false;
            panel.hidden = false;
            panel.setAttribute('aria-hidden', 'false');
            assistantRoot.classList.add('is-hidden');
            ensureWelcomeMessage();
            requestAnimationFrame(() => {
                backdrop.classList.add('is-open');
                panel.classList.add('is-open');
            });
            setTimeout(() => input.focus(), 140);
            return;
        }

        backdrop.classList.remove('is-open');
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        setTimeout(() => {
            if (!panel.classList.contains('is-open')) {
                backdrop.hidden = true;
                panel.hidden = true;
                assistantRoot.classList.remove('is-hidden');
            }
        }, 300); // Wait for transition
    }

    async function sendMessage(messageText) {
        hero.classList.add('has-messages');
        
        addMessage('user', messageText);
        history.push({ role: 'user', text: messageText });
        sendBtn.disabled = true;
        input.disabled = true;
        addMessage('meta', 'Thinking...');

        try {
            const response = await fetch('ajax_ai_assistant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    message: messageText,
                    history,
                    page: window.location.pathname
                })
            });

            const data = await response.json();
            messages.querySelector('.admin-ai-bubble.meta:last-child')?.remove();

            if (!response.ok || data.status !== 'success') {
                addMessage('assistant', data.message || 'Error processing request.');
                return;
            }

            if (data.action && data.action.message) {
                addMessage('meta', data.action.message);
            }

            const reply = data.reply || 'Completed, but received empty response.';
            addMessage('assistant', reply);
            history.push({ role: 'assistant', text: reply });
        } catch (error) {
            messages.querySelector('.admin-ai-bubble.meta:last-child')?.remove();
            addMessage('assistant', 'Unable to reach the AI Assistant at this moment.');
        } finally {
            sendBtn.disabled = false;
            input.disabled = false;
            input.focus();
            
            // Auto resize text area after sending
            input.style.height = 'auto';
        }
    }

    input.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    toggleBtn.addEventListener('click', () => setOpen(panel.hidden));
    backdrop.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            setOpen(false);
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const messageText = input.value.trim();
        if (!messageText) {
            return;
        }

        input.value = '';
        input.style.height = 'auto'; // Reset size
        sendMessage(messageText);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });
})();

// Shared admin UI translation toggle (Arabic <-> English)
(function() {
    const STORAGE_KEY = 'sport_admin_ui_lang';
    const COOKIE_VALUE_MAP = {
        ar: '/ar/ar',
        en: '/ar/en'
    };
    const toggle = document.getElementById('adminLanguageToggle');
    const code = document.getElementById('adminLanguageCode');
    const menu = document.getElementById('adminLanguageMenu');
    const optionButtons = menu ? Array.from(menu.querySelectorAll('[data-lang]')) : [];
    const translateHost = document.getElementById('google_translate_element');
    const root = document.documentElement;
    let pendingLanguage = localStorage.getItem(STORAGE_KEY) || 'ar';
    let observer = null;
    let observerTimer = null;
    let observerLocked = false;
    let translateBootRequested = false;

    if (!toggle || !menu || !translateHost) {
        return;
    }

    function getSavedLanguage() {
        return localStorage.getItem(STORAGE_KEY) || 'ar';
    }

    function setTranslateCookie(language) {
        const value = COOKIE_VALUE_MAP[language] || COOKIE_VALUE_MAP.ar;
        document.cookie = `googtrans=${value}; path=/`;
        document.cookie = `googtrans=${value}; domain=${window.location.hostname}; path=/`;
    }

    function updateLayoutLanguage(language) {
        root.dataset.adminLang = language;
        root.setAttribute('lang', language);
        root.setAttribute('dir', language === 'en' ? 'ltr' : 'rtl');
        if (code) {
            code.textContent = language.toUpperCase();
        }

        optionButtons.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.lang === language);
        });
    }

    function closeLanguageMenu() {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    function openLanguageMenu() {
        menu.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
    }

    function getTranslateCombo() {
        return document.querySelector('.goog-te-combo');
    }

    function scheduleObserverRefresh() {
        if (getSavedLanguage() !== 'en' || observerLocked) {
            return;
        }

        clearTimeout(observerTimer);
        observerTimer = setTimeout(() => {
            applyGoogleLanguage('en');
        }, 900);
    }

    function startMutationObserver() {
        if (observer || getSavedLanguage() !== 'en') {
            return;
        }

        observer = new MutationObserver(() => {
            scheduleObserverRefresh();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true
        });
    }

    function stopMutationObserver() {
        if (!observer) {
            return;
        }

        observer.disconnect();
        observer = null;
        clearTimeout(observerTimer);
    }

    function applyGoogleLanguage(language) {
        const combo = getTranslateCombo();
        if (!combo) {
            return false;
        }

        const targetLanguage = language === 'en' ? 'en' : 'ar';

        observerLocked = true;
        combo.value = targetLanguage;
        combo.dispatchEvent(new Event('change'));
        window.setTimeout(() => {
            observerLocked = false;
        }, 1600);

        return true;
    }

    function ensureGoogleTranslateScript() {
        if ((window.google && window.google.translate && window.google.translate.TranslateElement) || translateBootRequested) {
            return;
        }

        translateBootRequested = true;
        const script = document.createElement('script');
        script.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
        script.async = true;
        document.head.appendChild(script);
    }

    function applyLanguage(language, options = {}) {
        const forceReload = !!options.forceReload;
        pendingLanguage = language === 'en' ? 'en' : 'ar';
        localStorage.setItem(STORAGE_KEY, pendingLanguage);
        setTranslateCookie(pendingLanguage);
        updateLayoutLanguage(pendingLanguage);

        if (pendingLanguage === 'en') {
            ensureGoogleTranslateScript();
            startMutationObserver();

            if (!applyGoogleLanguage('en') && forceReload) {
                window.location.reload();
            }
        } else {
            stopMutationObserver();
            if (!applyGoogleLanguage('ar') && forceReload) {
                window.location.reload();
            }
        }
    }

    window.googleTranslateElementInit = function() {
        if (!translateHost.dataset.initialized) {
            new google.translate.TranslateElement({
                pageLanguage: 'ar',
                includedLanguages: 'ar,en',
                autoDisplay: false,
                layout: google.translate.TranslateElement.InlineLayout.SIMPLE
            }, 'google_translate_element');
            translateHost.dataset.initialized = '1';
        }

        const language = getSavedLanguage();
        updateLayoutLanguage(language);

        window.setTimeout(() => {
            applyGoogleLanguage(language);
            if (language === 'en') {
                startMutationObserver();
            }
        }, 500);
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        if (menu.hidden) {
            openLanguageMenu();
        } else {
            closeLanguageMenu();
        }
    });

    optionButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const language = button.dataset.lang === 'en' ? 'en' : 'ar';
            closeLanguageMenu();
            applyLanguage(language, { forceReload: true });
        });
    });

    document.addEventListener('click', (event) => {
        if (!menu.hidden && !menu.contains(event.target) && !toggle.contains(event.target)) {
            closeLanguageMenu();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !menu.hidden) {
            closeLanguageMenu();
        }
    });

    updateLayoutLanguage(pendingLanguage);

    if (pendingLanguage === 'en') {
        ensureGoogleTranslateScript();
        startMutationObserver();
    }
})();

// Silence routine client-side debug logs in production admin pages.
(function() {
    const debugEnabled = false;
    if (debugEnabled || !window.console) {
        return;
    }

    const prefixes = [
        '[CAST-DEBUG]',
        '[AIRPLAY-DEBUG]',
        'Auto Scraper executed:',
        'Auto Scraper Error:',
        'Auto refresh task failed:'
    ];

    ['log', 'warn', 'error', 'info', 'debug'].forEach((method) => {
        if (typeof console[method] !== 'function') {
            return;
        }

        const original = console[method].bind(console);
        console[method] = function(...args) {
            const firstArg = args.length > 0 ? args[0] : '';
            if (typeof firstArg === 'string') {
                const shouldSilence = prefixes.some((prefix) => firstArg.indexOf(prefix) === 0);
                if (shouldSilence) {
                    return;
                }
            }
            return original(...args);
        };
    });
})();

// Global Auto Runner - Runs in background on all admin pages
(function() {
    function runGlobalAutoScraper() {
        // Don't run if we are on settings page, as it has its own logger
        if (window.location.pathname.includes('settings.php')) return;

        fetch('../api/auto_scraper.php')
            .then(response => response.json())
            .then(data => {
                if (data.results && data.results.length > 0) {
                    console.log('Auto Scraper executed:', data.results);
                    // Optional: Show a small toast notification
                }
            })
            .catch(err => console.error('Auto Scraper Error:', err));
    }

    // Run every 40 seconds (to match the shortest interval)
    setInterval(runGlobalAutoScraper, 40000);

    // Global Session & Online Status Monitor
    // Checks every 15 seconds to ensure user is active and hasn't been deleted or disabled
    function monitorSession() {
        const formData = new FormData();
        formData.append('action', 'heartbeat');
        fetch('ajax_users.php', { method: 'POST', body: formData })
            .then(response => {
                if (response.status === 403) {
                    // Instantly kick the user out if their session is terminated or account disabled
                    window.location.href = 'login.php';
                }
                return response.json();
            })
            .then(data => {
                if (data && data.status === 'error' && data.message === 'تم إنهاء الجلسة') {
                    window.location.href = 'login.php';
                }
            })
            .catch(e => {
                // Ignore network errors, only act on explicit server rejection
            });
    }

    // Start session monitor
    monitorSession();
    setInterval(monitorSession, 15000);
})();

// Shared visibility-aware auto refresh manager for live admin pages.
window.AdminAutoRefresh = window.AdminAutoRefresh || (function() {
    const tasks = new Map();

    function clearTaskTimer(task) {
        if (task.timeoutId) {
            clearTimeout(task.timeoutId);
            task.timeoutId = null;
        }
    }

    function scheduleTask(task) {
        clearTaskTimer(task);

        if (document.hidden) {
            return;
        }

        task.timeoutId = setTimeout(() => {
            runTask(task.name);
        }, task.interval);
    }

    async function runTask(name, force = false) {
        const task = tasks.get(name);
        if (!task || task.running) {
            return;
        }

        if (!force && document.hidden) {
            scheduleTask(task);
            return;
        }

        task.running = true;

        try {
            await Promise.resolve(task.callback({
                name: task.name,
                force: !!force
            }));
            task.lastRunAt = Date.now();
        } catch (error) {
            console.error(`Auto refresh task failed: ${task.name}`, error);
        } finally {
            task.running = false;
            scheduleTask(task);
        }
    }

    function register(name, callback, options = {}) {
        const task = {
            name,
            callback,
            interval: Math.max(5000, Number(options.interval) || 15000),
            timeoutId: null,
            running: false,
            lastRunAt: 0,
            refreshOnVisible: options.refreshOnVisible !== false
        };

        tasks.set(name, task);

        if (options.runImmediately) {
            runTask(name, true);
        } else {
            scheduleTask(task);
        }

        return {
            stop() {
                unregister(name);
            },
            trigger(force = true) {
                runTask(name, force);
            }
        };
    }

    function unregister(name) {
        const task = tasks.get(name);
        if (!task) {
            return;
        }
        clearTaskTimer(task);
        tasks.delete(name);
    }

    function resumeAll(force = true) {
        tasks.forEach((task) => {
            if (task.refreshOnVisible && force) {
                runTask(task.name, true);
            } else {
                scheduleTask(task);
            }
        });
    }

    function pauseAll() {
        tasks.forEach(clearTaskTimer);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            pauseAll();
            return;
        }
        resumeAll(true);
    });

    window.addEventListener('focus', () => resumeAll(true));

    return {
        register,
        unregister,
        trigger: (name, force = true) => runTask(name, force),
        pauseAll,
        resumeAll
    };
})();

window.HayaTopLevelCastBridge = window.HayaTopLevelCastBridge || (function() {
    const castAppId = <?php echo json_encode(trim((string)(defined('SPORT_CAST_APP_ID') ? SPORT_CAST_APP_ID : '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    let sdkPromise = null;
    let castReady = false;
    const resolvedCastUrlCache = new Map();

    function ensureCastSdk() {
        if (!castAppId) {
            return Promise.reject(new Error('missing_cast_app_id'));
        }

        if (castReady && window.cast && window.cast.framework && window.chrome && window.chrome.cast) {
            return Promise.resolve();
        }

        if (sdkPromise) {
            return sdkPromise;
        }

        sdkPromise = new Promise((resolve, reject) => {
            if (window.cast && window.cast.framework && window.chrome && window.chrome.cast) {
                try {
                    initCastFramework();
                    resolve();
                } catch (error) {
                    reject(error);
                }
                return;
            }

            const previousCallback = window.__onGCastApiAvailable;
            window.__onGCastApiAvailable = function(isAvailable) {
                if (typeof previousCallback === 'function') {
                    try {
                        previousCallback(isAvailable);
                    } catch (error) {}
                }

                if (!isAvailable) {
                    reject(new Error('cast_sdk_unavailable'));
                    return;
                }

                try {
                    initCastFramework();
                    resolve();
                } catch (error) {
                    reject(error);
                }
            };

            const existingScript = document.querySelector('script[data-haya-cast-sdk="1"]');
            if (existingScript) {
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1';
            script.async = true;
            script.dataset.hayaCastSdk = '1';
            script.onerror = function() {
                reject(new Error('cast_sdk_load_failed'));
            };
            document.head.appendChild(script);
        });

        return sdkPromise;
    }

    function initCastFramework() {
        if (!window.cast || !window.cast.framework || !window.chrome || !window.chrome.cast) {
            console.error('[CAST-DEBUG] Bridge: cast framework objects missing');
            throw new Error('cast_framework_not_ready');
        }

        const context = window.cast.framework.CastContext.getInstance();
        console.log('[CAST-DEBUG] Bridge: setOptions appId=' + castAppId);
        context.setOptions({
            receiverApplicationId: castAppId,
            autoJoinPolicy: window.chrome.cast.AutoJoinPolicy.ORIGIN_SCOPED
        });

        context.addEventListener(
            window.cast.framework.CastContextEventType.SESSION_STATE_CHANGED,
            function(event) {
                console.log('[CAST-DEBUG] Bridge: session state changed:', event.sessionState);
            }
        );

        castReady = true;
        console.log('[CAST-DEBUG] Bridge: castReady = true');
        return context;
    }

    function buildCastLoadRequest(payload) {
        const mediaInfo = new window.chrome.cast.media.MediaInfo(payload.mediaUrl, 'application/x-mpegURL');
        mediaInfo.streamType = window.chrome.cast.media.StreamType.LIVE;
        const metadata = new window.chrome.cast.media.GenericMediaMetadata();
        metadata.title = payload.title || 'Live Stream';
        mediaInfo.metadata = metadata;

        const request = new window.chrome.cast.media.LoadRequest(mediaInfo);
        request.autoplay = true;
        return request;
    }

    function resolveCastPlaybackUrl(mediaUrl) {
        const rawUrl = (mediaUrl || '').trim();
        if (!rawUrl) {
            return Promise.resolve('');
        }

        if (resolvedCastUrlCache.has(rawUrl)) {
            return Promise.resolve(resolvedCastUrlCache.get(rawUrl));
        }

        let candidateUrl;
        try {
            candidateUrl = new URL(rawUrl, window.location.origin);
        } catch (error) {
            resolvedCastUrlCache.set(rawUrl, rawUrl);
            return Promise.resolve(rawUrl);
        }

        if (candidateUrl.origin !== window.location.origin) {
            resolvedCastUrlCache.set(rawUrl, rawUrl);
            return Promise.resolve(rawUrl);
        }

        return fetch(candidateUrl.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            redirect: 'follow',
            cache: 'no-store',
            headers: {
                'Accept': 'application/vnd.apple.mpegurl, application/x-mpegURL, */*'
            }
        }).then(function(response) {
            const finalUrl = response && response.ok && response.url ? response.url : rawUrl;
            resolvedCastUrlCache.set(rawUrl, finalUrl);
            return finalUrl;
        }).catch(function() {
            resolvedCastUrlCache.set(rawUrl, rawUrl);
            return rawUrl;
        });
    }

    function castMedia(payload) {
        console.log('[CAST-DEBUG] Bridge.castMedia() called', {
            castReady: castReady,
            hasPayload: !!payload,
            mediaUrl: payload ? payload.mediaUrl : null,
            hasCastFramework: !!(window.cast && window.cast.framework),
            hasChromeCast: !!(window.chrome && window.chrome.cast)
        });

        if (!castReady || !window.cast || !window.cast.framework || !window.chrome || !window.chrome.cast) {
            console.error('[CAST-DEBUG] Bridge.castMedia() → NOT READY');
            return Promise.reject(new Error('cast_not_ready'));
        }

        const context = window.cast.framework.CastContext.getInstance();
        const existingSession = context.getCurrentSession();
        const existingSessionObj = existingSession && typeof existingSession.getSessionObj === 'function'
            ? existingSession.getSessionObj()
            : null;
        const existingAppId = existingSessionObj && existingSessionObj.appId ? String(existingSessionObj.appId) : '';
        console.log('[CAST-DEBUG] Bridge.castMedia() existingSession:', !!existingSession, 'appId:', existingAppId || '(unknown)');

        if (existingSession) {
            if (existingAppId && castAppId && existingAppId !== castAppId) {
                console.warn('[CAST-DEBUG] Bridge.castMedia() ? existing session app mismatch, restarting session', {
                    existingAppId: existingAppId,
                    expectedAppId: castAppId
                });
                try {
                    context.endCurrentSession(true);
                } catch (error) {}

                return context.requestSession().then(function() {
                    const session = context.getCurrentSession();
                    console.log('[CAST-DEBUG] Bridge.castMedia() ? requestSession after mismatch, session:', !!session);
                    if (!session) {
                        throw new Error('cast_session_unavailable');
                    }

                    return resolveCastPlaybackUrl(payload.mediaUrl).then(function(resolvedMediaUrl) {
                        const request = buildCastLoadRequest(Object.assign({}, payload, { mediaUrl: resolvedMediaUrl }));
                        console.log('[CAST-DEBUG] Bridge.castMedia() ? loadMedia after mismatch recovery, contentId:', resolvedMediaUrl);
                        return session.loadMedia(request);
                    }).then(function() {
                        console.log('[CAST-DEBUG] Bridge.castMedia() ? loadMedia SUCCESS after mismatch recovery');
                        return true;
                    });
                }).catch(function(err) {
                    console.error('[CAST-DEBUG] Bridge.castMedia() ? FAILED after mismatch recovery', err);
                    throw err;
                });
            }

            console.log('[CAST-DEBUG] Bridge.castMedia() ? loadMedia on existing session');
            return resolveCastPlaybackUrl(payload.mediaUrl).then(function(resolvedMediaUrl) {
                const request = buildCastLoadRequest(Object.assign({}, payload, { mediaUrl: resolvedMediaUrl }));
                return existingSession.loadMedia(request);
            }).then(function() {
                console.log('[CAST-DEBUG] Bridge.castMedia() ? loadMedia SUCCESS');
                return true;
            }).catch(function(err) {
                console.error('[CAST-DEBUG] Bridge.castMedia() ? loadMedia FAILED', err);
                throw err;
            });
        }

        console.log('[CAST-DEBUG] Bridge.castMedia() → calling requestSession()');
        return context.requestSession().then(function() {
            const session = context.getCurrentSession();
            console.log('[CAST-DEBUG] Bridge.castMedia() → requestSession SUCCESS, session:', !!session);
            if (!session) {
                throw new Error('cast_session_unavailable');
            }

            return resolveCastPlaybackUrl(payload.mediaUrl).then(function(resolvedMediaUrl) {
                const request = buildCastLoadRequest(Object.assign({}, payload, { mediaUrl: resolvedMediaUrl }));
                console.log('[CAST-DEBUG] Bridge.castMedia() → calling loadMedia, contentId:', resolvedMediaUrl);
                return session.loadMedia(request);
            }).then(function() {
                console.log('[CAST-DEBUG] Bridge.castMedia() → loadMedia SUCCESS');
                return true;
            });
        }).catch(function(err) {
            console.error('[CAST-DEBUG] Bridge.castMedia() → FAILED', err);
            throw err;
        });
    }

    window.addEventListener('message', async function(event) {
        if (event.origin !== window.location.origin) {
            return;
        }

        const data = event.data || {};
        if (data.type !== 'haya_cast_request' || !data.requestId || !data.payload || !data.payload.mediaUrl) {
            return;
        }

        const sourceWindow = event.source;
        const reply = {
            type: 'haya_cast_ack',
            requestId: data.requestId,
            ok: false,
            error: ''
        };

        try {
            await castMedia(data.payload);
            reply.ok = true;
        } catch (error) {
            reply.error = error && error.message ? error.message : 'cast_failed';
        }

        try {
            if (sourceWindow && typeof sourceWindow.postMessage === 'function') {
                sourceWindow.postMessage(reply, event.origin);
            }
        } catch (error) {}
    });

    if (castAppId) {
        ensureCastSdk().catch(function () {});
    }

    return {
        castMedia,
        isReady: function() {
            return !!castReady;
        }
    };
})();

</script>
</body>
</html>
