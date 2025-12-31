/**
 * 智慧家教桥 Service Worker
 * PWA 离线缓存策略 - 遵循 .cursorrules 宪法
 */

const CACHE_NAME = 'tutor-bridge-v1.0.0';
const STATIC_CACHE = 'tutor-static-v1';
const DYNAMIC_CACHE = 'tutor-dynamic-v1';

// 核心静态资源 - 预缓存
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/detail.html',
  '/student_center.html',
  '/teacher_center.html',
  '/resources.html',
  '/help.html',
  // 默认图片资源
  '/assets/default_boy.png',
  '/assets/default_girl.png',
  // 外部CDN资源
  'https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css',
  'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css'
];

// 需要网络优先的API路径
const NETWORK_FIRST_PATHS = [
  '/api/'
];

// 安装事件 - 预缓存核心资源
self.addEventListener('install', event => {
  console.log('[SW] 安装中...');
  
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => {
        console.log('[SW] 预缓存静态资源');
        // 使用 addAll 但允许部分失败
        return Promise.allSettled(
          STATIC_ASSETS.map(url => 
            cache.add(url).catch(err => {
              console.warn(`[SW] 缓存失败: ${url}`, err);
              return null;
            })
          )
        );
      })
      .then(() => {
        console.log('[SW] 安装完成，立即激活');
        return self.skipWaiting();
      })
      .catch(err => {
        console.error('[SW] 安装失败:', err);
      })
  );
});

// 激活事件 - 清理旧缓存
self.addEventListener('activate', event => {
  console.log('[SW] 激活中...');
  
  event.waitUntil(
    caches.keys()
      .then(cacheNames => {
        return Promise.all(
          cacheNames
            .filter(name => name !== STATIC_CACHE && name !== DYNAMIC_CACHE)
            .map(name => {
              console.log(`[SW] 删除旧缓存: ${name}`);
              return caches.delete(name);
            })
        );
      })
      .then(() => {
        console.log('[SW] 激活完成，接管所有客户端');
        return self.clients.claim();
      })
  );
});

// 请求拦截 - 智能缓存策略
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);
  
  // 只处理 GET 请求
  if (request.method !== 'GET') {
    return;
  }
  
  // 跳过非同源和非CDN请求
  if (!url.origin.includes(self.location.origin) && 
      !url.origin.includes('cdn.bootcdn.net') && 
      !url.origin.includes('cdn.jsdelivr.net')) {
    return;
  }
  
  // API请求 - 网络优先策略
  if (NETWORK_FIRST_PATHS.some(path => url.pathname.includes(path))) {
    event.respondWith(networkFirst(request));
    return;
  }
  
  // 静态资源 - 缓存优先策略
  event.respondWith(cacheFirst(request));
});

/**
 * 缓存优先策略
 * 优先从缓存读取，缓存未命中则网络请求并缓存
 */
async function cacheFirst(request) {
  try {
    const cachedResponse = await caches.match(request);
    
    if (cachedResponse) {
      // 后台更新缓存（Stale-While-Revalidate）
      updateCache(request);
      return cachedResponse;
    }
    
    // 缓存未命中，网络请求
    const networkResponse = await fetch(request);
    
    if (networkResponse && networkResponse.ok) {
      const cache = await caches.open(DYNAMIC_CACHE);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    console.error('[SW] 请求失败:', error);
    
    // 返回离线页面或默认响应
    return new Response(
      `<html>
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>离线 | 智慧家教桥</title>
          <style>
            body {
              font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
              background: linear-gradient(135deg, #0B0F19, #0F172A, #1E1B4B);
              color: #FAFAFA;
              display: flex;
              flex-direction: column;
              align-items: center;
              justify-content: center;
              min-height: 100vh;
              margin: 0;
              padding: 20px;
              text-align: center;
            }
            .icon { font-size: 4rem; margin-bottom: 20px; opacity: 0.5; }
            h1 { font-size: 1.5rem; margin-bottom: 10px; }
            p { color: #94A3B8; margin-bottom: 24px; }
            button {
              background: linear-gradient(135deg, #6366F1, #818CF8);
              color: white;
              border: none;
              padding: 14px 32px;
              border-radius: 50px;
              font-weight: 600;
              cursor: pointer;
            }
          </style>
        </head>
        <body>
          <div class="icon">📡</div>
          <h1>网络连接已断开</h1>
          <p>请检查您的网络连接后重试</p>
          <button onclick="location.reload()">重新加载</button>
        </body>
      </html>`,
      {
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
        status: 503
      }
    );
  }
}

/**
 * 网络优先策略
 * 优先网络请求，失败时回退到缓存
 */
async function networkFirst(request) {
  try {
    const networkResponse = await fetch(request);
    
    if (networkResponse && networkResponse.ok) {
      const cache = await caches.open(DYNAMIC_CACHE);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    console.log('[SW] 网络请求失败，尝试缓存:', request.url);
    
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }
    
    // API请求返回JSON错误
    return new Response(
      JSON.stringify({
        status: 'error',
        message: '网络连接失败，请检查网络后重试',
        offline: true
      }),
      {
        headers: { 'Content-Type': 'application/json' },
        status: 503
      }
    );
  }
}

/**
 * 后台更新缓存
 */
async function updateCache(request) {
  try {
    const response = await fetch(request);
    if (response && response.ok) {
      const cache = await caches.open(DYNAMIC_CACHE);
      cache.put(request, response);
    }
  } catch (error) {
    // 静默失败
  }
}

// 处理推送通知（未来扩展）
self.addEventListener('push', event => {
  if (!event.data) return;
  
  const data = event.data.json();
  
  const options = {
    body: data.body || '您有一条新消息',
    icon: '/assets/icons/icon-192x192.png',
    badge: '/assets/icons/icon-72x72.png',
    vibrate: [100, 50, 100],
    data: {
      url: data.url || '/'
    },
    actions: [
      { action: 'open', title: '查看详情' },
      { action: 'close', title: '关闭' }
    ]
  };
  
  event.waitUntil(
    self.registration.showNotification(data.title || '智慧家教桥', options)
  );
});

// 通知点击事件
self.addEventListener('notificationclick', event => {
  event.notification.close();
  
  if (event.action === 'close') return;
  
  const url = event.notification.data?.url || '/';
  
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(clientList => {
        // 如果已有窗口打开，则聚焦
        for (const client of clientList) {
          if (client.url.includes(self.location.origin) && 'focus' in client) {
            client.navigate(url);
            return client.focus();
          }
        }
        // 否则打开新窗口
        return clients.openWindow(url);
      })
  );
});

console.log('[SW] Service Worker 已加载');

