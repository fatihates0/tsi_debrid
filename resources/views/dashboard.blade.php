<!DOCTYPE html>
<html lang="tr" class="dark h-full bg-slate-950">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSI Debrid & Cache Hub</title>

    <!-- Tailwind CSS (CDN for standalone single-file beauty) & Alpine.js -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            900: '#312e81',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        [x-cloak] {
            display: none !important;
        }

        .glass-card {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .glass-input {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .glass-input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.25);
        }

        .gradient-text {
            background: linear-gradient(135deg, #a5b4fc 0%, #6366f1 50%, #38bdf8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>

<body class="h-full text-slate-100 bg-slate-950 font-sans antialiased selection:bg-indigo-500 selection:text-white"
    x-data="debridApp()" x-init="initPolling()">

    <div class="min-h-full flex flex-col">
        <!-- TOP NAV BAR -->
        <header class="border-b border-slate-800/80 bg-slate-900/60 backdrop-blur-md sticky top-0 z-40">
            <div class="max-w-7xl mx-span px-4 sm:px-6 lg:px-8 py-3.5 mx-auto flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 via-violet-600 to-sky-400 p-0.5 shadow-lg shadow-indigo-500/20">
                        <div class="w-full h-full bg-slate-950 rounded-[10px] flex items-center justify-center">
                            <i class="fa-solid fa-bolt text-indigo-400 text-lg"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold tracking-tight text-white flex items-center gap-2">
                            TSI <span class="gradient-text font-black">DEBRID</span>
                        </h1>
                    </div>
                </div>

                <!-- REAL DEBRID ACCOUNT STATUS BADGE -->
                <div class="flex items-center gap-3">
                    <template x-if="rdInfo.loading">
                        <div class="flex flex-col items-end gap-1">
                            <div
                                class="flex items-center gap-2 text-xs text-slate-400 glass-card px-3 py-1.5 rounded-xl border border-slate-800">
                                <i class="fa-solid fa-spinner animate-spin text-indigo-400"></i> RD Hesabı
                                Sorgulanıyor...
                            </div>
                            <div class="text-[11px] font-mono text-slate-400 flex items-center gap-1.5 pr-1">
                                <i class="fa-solid fa-network-wired text-[10px] text-indigo-400"></i>
                                <span>Denenen IP:</span>
                                <span class="font-bold text-slate-300"
                                    x-text="rdInfo.active_proxy || 'Doğrudan'"></span>
                            </div>
                        </div>
                    </template>

                    <template x-if="!rdInfo.loading && rdInfo.success">
                        <div class="flex flex-col items-end gap-1">
                            <div
                                class="flex items-center gap-3 glass-card px-3.5 py-1.5 rounded-xl border border-indigo-500/20">
                                <div class="flex items-center gap-2">
                                    <span class="relative flex h-2.5 w-2.5">
                                        <span
                                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                        <span
                                            class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                                    </span>
                                    <span class="text-xs font-semibold text-slate-200"
                                        x-text="rdInfo.data.username"></span>
                                </div>
                                <span class="h-3 w-px bg-slate-700"></span>
                                <div class="text-xs text-indigo-300 font-medium flex items-center gap-1.5">
                                    <i class="fa-solid fa-crown text-amber-400"></i>
                                    <span x-text="rdInfo.data.type === 'premium' ? 'Premium Aktif' : 'Free'"></span>
                                </div>
                            </div>
                            <div class="text-[11px] font-mono text-indigo-300/90 flex items-center gap-1.5 pr-1">
                                <i class="fa-solid fa-network-wired text-[10px] text-indigo-400"></i>
                                <span>Aktif IP:</span>
                                <span class="font-bold text-white"
                                    x-text="rdInfo.active_proxy || rdInfo.data.active_proxy || 'Doğrudan'"></span>
                            </div>
                        </div>
                    </template>

                    <template x-if="!rdInfo.loading && !rdInfo.success">
                        <div class="flex flex-col items-end gap-1">
                            <div class="flex items-center gap-2 text-xs text-amber-400 glass-card px-3 py-1.5 rounded-xl border border-amber-500/30"
                                :title="rdInfo.message">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span x-text="rdInfo.message || 'RD Bağlantı Hatası (.env)'"></span>
                            </div>
                            <div class="text-[11px] font-mono text-amber-400/90 flex items-center gap-1.5 pr-1">
                                <i class="fa-solid fa-network-wired text-[10px] text-amber-400"></i>
                                <span>Denenen IP:</span>
                                <span class="font-bold text-amber-200"
                                    x-text="rdInfo.active_proxy || 'Doğrudan'"></span>
                            </div>
                        </div>
                    </template>
                </div>

                @auth
                    <!-- XENFORO USER PROFILE & LOGOUT -->
                    <div class="flex items-center gap-3 pl-3 border-l border-slate-800">
                        <div class="flex items-center gap-2">
                            @if(Auth::user()->avatar_url)
                                <img src="{{ Auth::user()->avatar_url }}" alt="{{ Auth::user()->name }}"
                                    class="w-8 h-8 rounded-full border border-indigo-500/40 object-cover">
                            @else
                                <div
                                    class="w-8 h-8 rounded-full bg-indigo-600/30 border border-indigo-500/40 flex items-center justify-center text-indigo-300 font-bold text-xs">
                                    {{ strtoupper(substr(Auth::user()->name ?? 'U', 0, 1)) }}
                                </div>
                            @endif
                            <div class="hidden sm:flex flex-col">
                                <span class="text-xs font-bold text-white leading-tight">{{ Auth::user()->name }}</span>
                                <span class="text-[10px] text-indigo-400 font-medium">turkcesesindir.com</span>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" title="Çıkış Yap"
                                class="p-2 rounded-xl bg-slate-800/80 hover:bg-red-500/20 text-slate-400 hover:text-red-400 border border-slate-700/60 hover:border-red-500/30 transition text-xs flex items-center gap-1.5">
                                <i class="fa-solid fa-right-from-bracket"></i>
                                <span class="hidden md:inline">Çıkış</span>
                            </button>
                        </form>
                    </div>
                @endauth
            </div>
        </header>

        <!-- MAIN CONTENT AREA -->
        <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

            <!-- FLASH MESSAGES -->
            @if(session('success'))
                <div
                    class="p-4 rounded-xl bg-emerald-950/60 border border-emerald-500/40 text-emerald-300 flex items-center justify-between shadow-lg">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-circle-check text-emerald-400 text-lg"></i>
                        <span class="font-medium text-sm">{{ session('success') }}</span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-200"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
            @endif

            @if(session('info'))
                <div
                    class="p-4 rounded-xl bg-sky-950/60 border border-sky-500/40 text-sky-300 flex items-center justify-between shadow-lg">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-circle-info text-sky-400 text-lg"></i>
                        <span class="font-medium text-sm">{{ session('info') }}</span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-sky-400 hover:text-sky-200"><i
                            class="fa-solid fa-xmark"></i></button>
                </div>
            @endif

            @if($errors->any())
                <div class="p-4 rounded-xl bg-rose-950/60 border border-rose-500/40 text-rose-300 space-y-1 shadow-lg">
                    @foreach($errors->all() as $error)
                        <div class="flex items-center gap-2 text-sm">
                            <i class="fa-solid fa-triangle-exclamation text-rose-400"></i>
                            <span>{{ $error }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- HERO SUBMISSION FORM -->
            <div class="glass-card rounded-2xl p-6 shadow-2xl relative">
                <form @submit.prevent="submitLink()" class="space-y-4">
                    <label for="link" class="block text-sm font-semibold text-slate-200">
                        İndirme Bağlantısı
                    </label>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="relative flex-1">
                            <div
                                class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <i class="fa-solid fa-link"></i>
                            </div>
                            <input type="url" x-model="inputUrl" id="link" required
                                placeholder="https://mega.nz/file/..."
                                class="w-full pl-11 pr-24 py-3.5 rounded-xl glass-input text-sm text-white placeholder-slate-500 focus:outline-none transition-all duration-200">
                            <button type="button" @click="pasteClipboard()"
                                class="absolute right-2.5 top-2.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition-colors flex items-center gap-1.5">
                                <i class="fa-solid fa-paste"></i> Yapıştır
                            </button>
                        </div>
                        <button type="submit" :disabled="isSubmitting"
                            class="px-7 py-3.5 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-semibold text-sm shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/50 transition-all duration-200 flex items-center justify-center gap-2 shrink-0 disabled:opacity-50">
                            <span x-show="!isSubmitting" class="flex items-center gap-2">
                                <i class="fa-solid fa-cloud-arrow-down"></i>
                                <span>İndir & Önbellekle</span>
                            </span>
                            <span x-show="isSubmitting" x-cloak class="flex items-center gap-2">
                                <i class="fa-solid fa-spinner animate-spin"></i>
                                <span>Kuyruğa Alınıyor...</span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- STATS CARDS GRID -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center justify-between text-slate-400 text-xs font-medium">
                        <span>Toplam İndirme Kaydı</span>
                        <i class="fa-solid fa-list-check text-indigo-400"></i>
                    </div>
                    <div class="text-2xl font-bold text-white mt-2">{{ $stats['total_downloads'] }}</div>
                </div>

                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center justify-between text-slate-400 text-xs font-medium">
                        <span>Önbelleğe Alınan (Tamamlanan)</span>
                        <i class="fa-solid fa-hard-drive text-emerald-400"></i>
                    </div>
                    <div class="text-2xl font-bold text-emerald-400 mt-2">{{ $stats['completed_downloads'] }}</div>
                </div>

                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center justify-between text-slate-400 text-xs font-medium">
                        <span>Sunucu Disk Kullanımı</span>
                        <i class="fa-solid fa-box-archive text-sky-400"></i>
                    </div>
                    <div class="text-2xl font-bold text-sky-400 mt-2">
                        {{ formatBytes($stats['total_bytes_cached']) }}
                    </div>
                </div>

                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center justify-between text-slate-400 text-xs font-medium">
                        <span>Engellenen RD Trafiği (Tasarruf)</span>
                        <i class="fa-solid fa-shield-cat text-amber-400"></i>
                    </div>
                    <div class="text-2xl font-bold text-amber-400 mt-2">
                        {{ $stats['total_saved_rd_requests'] }} <span
                            class="text-xs font-normal text-slate-400">istek</span>
                    </div>
                </div>
            </div>

            <!-- ACTIVE & CACHED DOWNLOADS TABLE CONTAINER -->
            <div class="glass-card rounded-2xl overflow-hidden border border-slate-800">
                <div class="p-5 border-b border-slate-800/80 flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-server text-indigo-400"></i>
                            <span>İndirmeler & Önbellek Listesi</span>
                        </h3>
                        <p class="text-xs text-slate-400">Arka plan indirme durumları 2 saniyede bir otomatik
                            güncellenir.</p>
                    </div>
                    <button @click="fetchDownloads()"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition flex items-center gap-1.5">
                        <i class="fa-solid fa-rotate text-indigo-400" :class="{'animate-spin': isRefreshing}"></i>
                        Yenile
                    </button>
                </div>

                <!-- TABLE / LIST -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead
                            class="bg-slate-900/80 text-xs uppercase text-slate-400 font-semibold border-b border-slate-800">
                            <tr>
                                <th class="px-5 py-3.5">Dosya Adı & Link</th>
                                <th class="px-5 py-3.5">Boyut</th>
                                <th class="px-5 py-3.5">Durum & İlerleme</th>
                                <th class="px-5 py-3.5 text-center">İndirme Sayısı</th>
                                <th class="px-5 py-3.5 text-right">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <template x-for="item in downloadsList" :key="item.id">
                                <tr class="hover:bg-slate-900/40 transition-colors">
                                    <!-- FILE NAME & LINK -->
                                    <td class="px-5 py-4 max-w-xs sm:max-w-md">
                                        <div class="font-semibold text-white truncate flex items-center gap-2"
                                            :title="item.filename || 'Dosya Adı Bekleniyor...'">
                                            <i class="fa-regular fa-file-lines text-indigo-400"></i>
                                            <span x-text="item.filename || 'Dönüştürülüyor...'"></span>
                                        </div>
                                        <div class="text-xs text-slate-400 truncate mt-1" :title="item.original_link">
                                            <span class="text-slate-500 font-mono">Mega:</span> <span
                                                x-text="item.original_link"></span>
                                        </div>
                                    </td>

                                    <!-- FILESIZE -->
                                    <td class="px-5 py-4 text-xs font-mono text-slate-300 whitespace-nowrap">
                                        <span x-text="formatBytesJS(item.filesize)"></span>
                                    </td>

                                    <!-- STATUS & PROGRESS -->
                                    <td class="px-5 py-4 min-w-[200px]">
                                        <!-- COMPLETED STATUS -->
                                        <template x-if="item.status === 'completed'">
                                            <div class="space-y-1">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                    <i class="fa-solid fa-circle-check"></i> Önbellekte Hazır
                                                </span>
                                            </div>
                                        </template>

                                        <!-- DOWNLOADING STATUS -->
                                        <template
                                            x-if="item.status === 'downloading' || item.status === 'unrestricting' || item.status === 'pending'">
                                            <div class="space-y-1.5">
                                                <div class="flex justify-between text-xs">
                                                    <span class="text-indigo-400 font-medium flex items-center gap-1">
                                                        <i class="fa-solid fa-spinner animate-spin"></i>
                                                        <span
                                                            x-text="item.status === 'downloading' ? 'İndiriliyor...' : 'Real-Debrid Bekleniyor'"></span>
                                                    </span>
                                                    <span class="font-mono text-slate-300"
                                                        x-text="getProgress(item) + '%'"></span>
                                                </div>
                                                <div class="w-full bg-slate-800 rounded-full h-2 overflow-hidden">
                                                    <div class="bg-gradient-to-r from-indigo-500 to-sky-400 h-2 rounded-full transition-all duration-300"
                                                        :style="'width: ' + getProgress(item) + '%'"></div>
                                                </div>
                                                <div class="text-[11px] text-slate-400 font-mono"
                                                    x-text="formatBytesJS(item.downloaded_bytes) + ' / ' + formatBytesJS(item.filesize)">
                                                </div>
                                            </div>
                                        </template>

                                        <!-- FAILED STATUS -->
                                        <template x-if="item.status === 'failed'">
                                            <div>
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20"
                                                    :title="item.error_message">
                                                    <i class="fa-solid fa-circle-xmark"></i> Hata Oluştu
                                                </span>
                                                <p class="text-[11px] text-rose-400/80 truncate mt-1 max-w-[180px]"
                                                    x-text="item.error_message"></p>
                                            </div>
                                        </template>
                                    </td>

                                    <!-- DOWNLOAD COUNT -->
                                    <td class="px-5 py-4 text-center text-xs font-mono font-bold text-amber-400">
                                        <span x-text="item.download_count"></span> x
                                    </td>

                                    <!-- ACTIONS -->
                                    <td class="px-5 py-4 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-2">
                                            <!-- DIRECT PROXY DOWNLOAD BUTTON -->
                                            <template x-if="item.status === 'completed'">
                                                <a :href="'/dl/' + item.uuid" target="_blank"
                                                    class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold shadow transition-all flex items-center gap-1.5">
                                                    <i class="fa-solid fa-download"></i> İndir
                                                </a>
                                            </template>

                                            <!-- COPY IDM LINK BUTTON -->
                                            <button @click="copyIdmLink(item)"
                                                class="p-1.5 rounded-lg bg-indigo-950 hover:bg-indigo-900 text-indigo-300 text-xs transition"
                                                title="IDM Uyumlu Kısa Bağlantıyı Kopyala">
                                                <i class="fa-solid fa-bolt"></i>
                                            </button>

                                            <!-- COPY PROXY LINK BUTTON -->
                                            <template x-if="item.status === 'completed'">
                                                <button @click="copyLink(window.location.origin + '/dl/' + item.uuid)"
                                                    class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition"
                                                    title="Proxy İndirme Linkini Kopyala">
                                                    <i class="fa-solid fa-copy"></i>
                                                </button>
                                            </template>

                                            <!-- DELETE / CANCEL BUTTON -->
                                            <button @click="deleteItem(item.uuid)"
                                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-950 hover:text-rose-400 text-slate-400 text-xs transition"
                                                :title="['pending', 'unrestricting', 'downloading'].includes(item.status) ? 'İndirmeyi İptal Et ve Sil' : 'Önbelleği Sil'">
                                                <i class="fa-solid"
                                                    :class="['pending', 'unrestricting', 'downloading'].includes(item.status) ? 'fa-xmark text-rose-400' : 'fa-trash'"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <template x-if="downloadsList.length === 0">
                                <tr>
                                    <td colspan="5" class="px-5 py-12 text-center text-slate-500 text-sm">
                                        <i class="fa-solid fa-box-open text-3xl text-slate-600 mb-2 block"></i>
                                        Henüz önbelleğe alınmış indirme yok. Yukarıdaki formdan bir link
                                        ekleyin.
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- REST API QUICK REFERENCE CARD -->
            <div class="glass-card rounded-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-code text-indigo-400"></i>
                    <span>REST API Entegrasyon Rehberi</span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs font-mono">
                    <div class="bg-slate-900/90 rounded-xl p-3.5 border border-slate-800 space-y-2">
                        <div class="text-amber-400 font-bold">GET /api/indir/{link}</div>
                        <div class="text-slate-400"><i class="fa-solid fa-bolt text-amber-400"></i> IDM & Direk İndirme
                            Endpoint'i</div>
                        <div class="text-slate-500">IDM'ye eklendiğinde linki anında Real-Debrid ile çözüp indirmeyi
                            başlatır.</div>
                    </div>
                    <div class="bg-slate-900/90 rounded-xl p-3.5 border border-slate-800 space-y-2">
                        <div class="text-emerald-400 font-bold">POST /api/v1/downloads</div>
                        <div class="text-slate-400">Body: <code
                                class="text-indigo-300">{"link": "https://mega.nz/..."}</code></div>
                        <div class="text-slate-500">Real-Debrid üzerinden 1 kez indirir veya var olan önbellek linkini
                            döner.</div>
                    </div>
                    <div class="bg-slate-900/90 rounded-xl p-3.5 border border-slate-800 space-y-2">
                        <div class="text-sky-400 font-bold">GET /api/v1/downloads/{uuid}</div>
                        <div class="text-slate-400">Durum sorgulama & canlı indirme yüzdesi (progress) alır.</div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ALPINE JS APP SCRIPT -->
    <script>
        function debridApp() {
            return {
                inputUrl: '',
                isSubmitting: false,
                downloadsList: [],
                isRefreshing: false,
                rdInfo: {
                    loading: true,
                    success: false,
                    active_proxy: '{{ \App\Services\RealDebridService::getDisplayProxy(\App\Services\RealDebridService::getCandidateProxiesForApi()[0] ?? null) }}',
                    data: {}
                },

                initPolling() {
                    this.fetchRdStatus();
                    this.fetchDownloads();
                    setInterval(() => {
                        this.fetchDownloads(true);
                    }, 2500);
                },

                async fetchRdStatus() {
                    try {
                        const res = await fetch('/rd-status');
                        const json = await res.json();
                        this.rdInfo.loading = false;
                        if (json && json.active_proxy) {
                            this.rdInfo.active_proxy = json.active_proxy;
                        }
                        if (json && json.success) {
                            this.rdInfo.success = true;
                            this.rdInfo.data = json.data;
                            if (json.data && json.data.active_proxy) {
                                this.rdInfo.active_proxy = json.data.active_proxy;
                            }
                        } else {
                            this.rdInfo.success = false;
                            this.rdInfo.message = json ? json.message : 'RD Bağlantı Hatası (.env)';
                        }
                    } catch (e) {
                        this.rdInfo.loading = false;
                        this.rdInfo.success = false;
                    }
                },

                async fetchDownloads(silent = false) {
                    if (!silent) this.isRefreshing = true;
                    try {
                        const res = await fetch('/downloads/ajax-list');
                        const json = await res.json();
                        if (json.success) {
                            this.downloadsList = json.data;
                        }
                    } catch (e) {
                        console.error('Fetch error:', e);
                    } finally {
                        if (!silent) this.isRefreshing = false;
                    }
                },

                async deleteItem(uuid) {
                    const item = this.downloadsList.find(d => d.uuid === uuid);
                    const isRunning = item && ['pending', 'unrestricting', 'downloading'].includes(item.status);
                    const promptText = isRunning
                        ? 'Bu indirmeyi durdurup iptal etmek ve kaydı silmek istediğinize emin misiniz?'
                        : 'Bu önbellek dosyasını ve kaydını silmek istediğinize emin misiniz?';

                    if (!confirm(promptText)) return;

                    // Instantly remove from local list for snappy UI feedback
                    this.downloadsList = this.downloadsList.filter(d => d.uuid !== uuid);

                    try {
                        const res = await fetch('/downloads/' + uuid, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Accept': 'application/json'
                            }
                        });
                        const json = await res.json();
                        if (json && json.success) {
                            this.fetchDownloads(true);
                        }
                    } catch (e) {
                        this.fetchDownloads(true);
                        alert('İşlem sırasında hata oluştu.');
                    }
                },

                async submitLink() {
                    const link = (this.inputUrl || '').trim();
                    if (!link) return;

                    this.isSubmitting = true;
                    try {
                        const res = await fetch('/downloads', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ link })
                        });
                        const json = await res.json();
                        if (json && json.success) {
                            this.inputUrl = '';
                            this.fetchDownloads(true);
                        } else {
                            alert(json?.message || 'İndirme kuyruğa eklenirken hata oluştu.');
                        }
                    } catch (e) {
                        alert('İstek gönderilirken hata oluştu.');
                    } finally {
                        this.isSubmitting = false;
                    }
                },

                async pasteClipboard() {
                    try {
                        const text = await navigator.clipboard.readText();
                        if (text) {
                            this.inputUrl = text.trim();
                        }
                    } catch (e) {
                        alert('Panoya erişilemedi.');
                    }
                },

                copyLink(text) {
                    navigator.clipboard.writeText(text);
                    alert('⚡ Proxy İndirme Linki Panoya Kopyalandı:\n' + text);
                },

                copyIdmLink(item) {
                    if (!item) return;
                    const idmUrl = window.location.origin + '/api/indir/' + (item.id || item.uuid);
                    navigator.clipboard.writeText(idmUrl);
                    alert('⚡ IDM Uyumlu Bağlantı Kopyalandı!\nIDM\'ye doğrudan yapıştırabilirsiniz:\n\n' + idmUrl);
                },

                getProgress(item) {
                    if (item.status === 'completed') return 100;
                    if (!item.filesize || item.filesize <= 0) return 0;
                    return Math.min(100, Math.round((item.downloaded_bytes / item.filesize) * 100));
                },

                formatBytesJS(bytes) {
                    if (!bytes || bytes <= 0) return '0 B';
                    const k = 1024;
                    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
                }
            }
        }
    </script>
</body>

</html>