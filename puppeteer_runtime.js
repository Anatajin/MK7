const fs = require('fs');
const path = require('path');

function ensureDir(dirPath) {
    fs.mkdirSync(dirPath, { recursive: true });
    return dirPath;
}

function existingFile(candidates) {
    return candidates.find((candidate) => candidate && fs.existsSync(candidate)) || null;
}

function findBundledChrome(cacheRoots) {
    for (const root of cacheRoots) {
        if (!root || !fs.existsSync(root)) {
            continue;
        }

        const directMatch = existingFile([
            path.join(root, 'chrome-linux64', 'chrome'),
            path.join(root, 'chrome-win64', 'chrome.exe'),
            path.join(root, 'chrome', 'chrome-linux64', 'chrome'),
            path.join(root, 'chrome', 'chrome-win64', 'chrome.exe')
        ]);
        if (directMatch) {
            return directMatch;
        }

        const chromeDir = path.join(root, 'chrome');
        if (!fs.existsSync(chromeDir)) {
            continue;
        }

        try {
            const matches = fs.readdirSync(chromeDir, { withFileTypes: true })
                .filter((entry) => entry.isDirectory())
                .map((entry) => existingFile([
                    path.join(chromeDir, entry.name, 'chrome-linux64', 'chrome'),
                    path.join(chromeDir, entry.name, 'chrome-win64', 'chrome.exe')
                ]))
                .filter(Boolean)
                .sort()
                .reverse();

            if (matches.length > 0) {
                return matches[0];
            }
        } catch (error) {
            // Ignore cache directory read failures and continue to the next root.
        }
    }

    return null;
}

function preparePuppeteerRuntime(baseDir = __dirname) {
    const rootDir = path.resolve(baseDir);
    const projectCacheDir = path.join(rootDir, '.puppeteer-cache');

    // Only pin Puppeteer's cache when the project-local browser cache already exists.
    if (!process.env.PUPPETEER_CACHE_DIR && fs.existsSync(projectCacheDir)) {
        process.env.PUPPETEER_CACHE_DIR = projectCacheDir;
    }

    process.env.PUPPETEER_DISABLE_CRASHPAD = '1';

    const runtimeRoot = ensureDir(path.join(rootDir, '.puppeteer-runtime'));
    const homeDir = ensureDir(path.join(runtimeRoot, 'home'));
    const configDir = ensureDir(path.join(runtimeRoot, 'config'));
    const cacheDir = ensureDir(path.join(runtimeRoot, 'cache'));
    const dataDir = ensureDir(path.join(runtimeRoot, 'data'));
    const userDataRoot = ensureDir(path.join(runtimeRoot, 'user-data'));
    const stableUserDataDir = process.env.PUPPETEER_USER_DATA_DIR || '';
    const userDataDir = ensureDir(
        stableUserDataDir || path.join(
            userDataRoot,
            `${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
        )
    );

    if (!stableUserDataDir) {
        process.once('exit', () => {
            try {
                fs.rmSync(userDataDir, { recursive: true, force: true });
            } catch (error) {
                // Runtime cleanup is best-effort.
            }
        });
    }

    const applicationsDir = ensureDir(path.join(dataDir, 'applications'));
    const mimeAppsList = path.join(applicationsDir, 'mimeapps.list');
    if (!fs.existsSync(mimeAppsList)) {
        fs.writeFileSync(mimeAppsList, '', 'utf8');
    }

    if (process.platform === 'linux') {
        process.env.HOME = homeDir;
        process.env.XDG_CONFIG_HOME = configDir;
        process.env.XDG_CACHE_HOME = cacheDir;
        process.env.XDG_DATA_HOME = dataDir;
        process.env.CHROME_CONFIG_HOME = configDir;
        process.env.CHROME_USER_DATA_DIR = userDataDir;
    }

    return {
        runtimeRoot,
        homeDir,
        configDir,
        cacheDir,
        dataDir,
        userDataDir
    };
}

function resolveExecutablePath(puppeteer) {
    const envPath = existingFile([
        process.env.PUPPETEER_EXECUTABLE_PATH,
        process.env.CHROME_PATH
    ]);

    if (envPath) {
        return envPath;
    }

    const bundledCachePath = findBundledChrome([
        process.env.PUPPETEER_CACHE_DIR,
        path.join(process.cwd(), '.puppeteer-cache'),
        path.join(__dirname, '.puppeteer-cache'),
        '/var/www/html/.puppeteer-cache',
        '/root/.cache/puppeteer'
    ]);
    if (bundledCachePath) {
        return bundledCachePath;
    }

    try {
        const bundledPath = puppeteer.executablePath();
        if (bundledPath && fs.existsSync(bundledPath)) {
            return bundledPath;
        }
    } catch (error) {
        // Fall back to common system paths when Puppeteer's bundled browser is unavailable.
    }

    return existingFile([
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
        '/opt/google/chrome/chrome',
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'
    ]);
}

function buildLaunchOptions(puppeteer, runtime, extraOptions = {}) {
    const baseArgs = [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--no-zygote',
        '--disable-breakpad',
        '--disable-crash-reporter',
        `--user-data-dir=${runtime.userDataDir}`
    ];

    const extraArgs = Array.isArray(extraOptions.args) ? extraOptions.args : [];
    const { args, ...otherOptions } = extraOptions;

    const launchOptions = {
        headless: true,
        userDataDir: runtime.userDataDir,
        args: [...baseArgs, ...extraArgs],
        ...otherOptions
    };

    const executablePath = resolveExecutablePath(puppeteer);
    if (executablePath) {
        launchOptions.executablePath = executablePath;
    }

    return launchOptions;
}

module.exports = {
    preparePuppeteerRuntime,
    buildLaunchOptions,
    resolveExecutablePath
};
