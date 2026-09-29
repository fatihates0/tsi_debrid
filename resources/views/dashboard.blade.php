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

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .animate-slide-in {
            animation: slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
    </style>
</head>

<body class="h-full text-slate-100 bg-slate-950 font-sans antialiased selection:bg-indigo-500 selection:text-white"
    x-data="debridApp()" x-init="initPolling()">

    <!-- TOAST NOTIFICATION CONTAINER (Top Right, Auto Disappears in 3s) -->
    <div class="fixed top-5 right-5 z-50 flex flex-col gap-2.5 pointer-events-none max-w-sm w-full px-4" x-cloak>
        <template x-for="toast in toasts" :key="toast.id">
            <div class="pointer-events-auto rounded-xl p-4 shadow-2xl border flex items-start gap-3 animate-slide-in backdrop-blur-md transition-all duration-300"
                :class="{
                    'bg-slate-900/95 border-emerald-500/30 text-emerald-300 shadow-emerald-950/40': toast.type === 'success',
                    'bg-slate-900/95 border-rose-500/30 text-rose-300 shadow-rose-950/40': toast.type === 'error',
                    'bg-slate-900/95 border-amber-500/30 text-amber-300 shadow-amber-950/40': toast.type === 'warning',
                    'bg-slate-900/95 border-indigo-500/30 text-indigo-300 shadow-indigo-950/40': toast.type === 'info'
                }">
                <div class="shrink-0 text-base mt-0.5">
                    <template x-if="toast.type === 'success'"><i
                            class="fa-solid fa-circle-check text-emerald-400"></i></template>
                    <template x-if="toast.type === 'error'"><i
                            class="fa-solid fa-circle-xmark text-rose-400"></i></template>
                    <template x-if="toast.type === 'warning'"><i
                            class="fa-solid fa-triangle-exclamation text-amber-400"></i></template>
                    <template x-if="toast.type === 'info'"><i
                            class="fa-solid fa-circle-info text-indigo-400"></i></template>
                </div>
                <div class="flex-1 text-xs font-medium leading-relaxed" x-text="toast.message"></div>
                <button @click="removeToast(toast.id)" class="text-slate-400 hover:text-white transition shrink-0">
                    <i class="fa-solid fa-xmark text-xs"></i>
                </button>
            </div>
        </template>
    </div>

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
                            @if(!empty($isSuperUser) && $isSuperUser)
                                <div class="text-[11px] font-mono text-slate-400 flex items-center gap-1.5 pr-1">
                                    <i class="fa-solid fa-network-wired text-[10px] text-indigo-400"></i>
                                    <span>Denenen IP:</span>
                                    <span class="font-bold text-slate-300"
                                        x-text="rdInfo.active_proxy || 'Doğrudan'"></span>
                                </div>
                            @endif
                        </div>
                    </template>

                    <template x-if="!rdInfo.loading && rdInfo.success">
                        <div class="flex flex-col items-end gap-1">
                            <div
                                class="flex items-center gap-3 glass-card px-3.5 py-1.5 rounded-xl border border-indigo-500/20">
                                <div class="text-xs text-indigo-300 font-medium flex items-center gap-1.5">
                                    <i class="fa-solid fa-crown text-amber-400"></i>
                                    <span x-text="rdInfo.data.type === 'premium' ? 'Premium Aktif' : 'Free'"></span>
                                    <i class="fa-solid fa-crown text-amber-400"></i>
                                </div>
                            </div>
                            @if(!empty($isSuperUser) && $isSuperUser)
                                <div class="text-[11px] font-mono text-indigo-300/90 flex items-center gap-1.5 pr-1">
                                    <i class="fa-solid fa-network-wired text-[10px] text-indigo-400"></i>
                                    <span>Aktif IP:</span>
                                    <span class="font-bold text-white"
                                        x-text="rdInfo.active_proxy || rdInfo.data.active_proxy || 'Doğrudan'"></span>
                                </div>
                            @endif
                        </div>
                    </template>

                    <template x-if="!rdInfo.loading && !rdInfo.success">
                        <div class="flex flex-col items-end gap-1">
                            <div class="flex items-center gap-2 text-xs text-amber-400 glass-card px-3 py-1.5 rounded-xl border border-amber-500/30"
                                :title="rdInfo.message">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span x-text="rdInfo.message || 'RD Bağlantı Hatası (.env)'"></span>
                            </div>
                            @if(!empty($isSuperUser) && $isSuperUser)
                                <div class="text-[11px] font-mono text-amber-400/90 flex items-center gap-1.5 pr-1">
                                    <i class="fa-solid fa-network-wired text-[10px] text-amber-400"></i>
                                    <span>Denenen IP:</span>
                                    <span class="font-bold text-amber-200"
                                        x-text="rdInfo.active_proxy || 'Doğrudan'"></span>
                                </div>
                            @endif
                        </div>
                    </template>
                </div>

                @auth
                    <!-- XENFORO USER PROFILE & LOGOUT -->
                    <div class="flex items-center gap-3 pl-3 border-l border-slate-800">
                        @if(!empty($isSuperUser) && $isSuperUser)
                            <div
                                class="px-2.5 py-1 rounded-lg bg-amber-500/20 border border-amber-500/40 text-amber-300 font-bold text-xs flex items-center gap-1.5">
                                <i class="fa-solid fa-shield-halved text-amber-400"></i> SUPERUSER
                            </div>
                        @endif
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
                                <span class="text-[10px] text-indigo-400 font-medium">
                                    @if(!empty($isSuperUser) && $isSuperUser)
                                        Süper Yönetici
                                    @else
                                        turkcesesindir.com
                                    @endif
                                </span>
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
            <div class="grid grid-cols-1 sm:grid-cols-2 {{ (!empty($isSuperUser) && $isSuperUser) ? 'lg:grid-cols-5' : 'lg:grid-cols-4' }} gap-4">
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

                @if(!empty($isSuperUser) && $isSuperUser)
                    <div class="glass-card rounded-xl p-4 border border-violet-500/30">
                        <div class="flex items-center justify-between text-slate-400 text-xs font-medium">
                            <span>Sunucu Boş Disk Alanı</span>
                            <i class="fa-solid fa-server text-violet-400"></i>
                        </div>
                        <div class="text-2xl font-bold text-violet-300 mt-2 font-mono">
                            {{ formatBytes($stats['free_disk_space']) }}
                        </div>
                        @if(!empty($stats['total_disk_space']) && $stats['total_disk_space'] > 0)
                            <div class="text-[11px] text-slate-400 mt-1 font-mono">
                                Toplam: <span class="text-slate-300">{{ formatBytes($stats['total_disk_space']) }}</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            @if(!empty($isSuperUser) && $isSuperUser)
                <!-- SUPERUSER USER STATISTICS & MONITORING PANEL -->
                <div class="glass-card rounded-2xl p-6 border border-amber-500/30 space-y-6">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 rounded-xl bg-amber-500/20 border border-amber-500/40 flex items-center justify-center text-amber-400">
                                <i class="fa-solid fa-users-gear text-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-white flex items-center gap-2">
                                    <span>Superuser Yönetim Paneli</span>
                                    <span
                                        class="text-xs px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30">Canlı
                                        İstatistikler</span>
                                </h3>
                                <p class="text-xs text-slate-400">Tüm kullanıcıların önbellekleme durumları, aktif IP
                                    adresleri ve bağlantıları</p>
                            </div>
                        </div>
                    </div>

                    <!-- USER STATS TABLE -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-300">
                            <thead
                                class="bg-slate-900/90 text-xs uppercase text-slate-400 font-semibold border-b border-slate-800">
                                <tr>
                                    <th class="px-4 py-3">Kullanıcı</th>
                                    <th class="px-4 py-3 text-center">Önbelleklenen Dosya</th>
                                    <th class="px-4 py-3 text-center">Toplam Boyut</th>
                                    <th class="px-4 py-3 text-center">Aktif Bağlantı</th>
                                    <th class="px-4 py-3 text-right">Aktif IP</th>
                                    <th class="px-4 py-3 text-right">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                <template x-for="uStat in paginatedUserStats()" :key="uStat.id">
                                    <tr class="hover:bg-slate-900/40 transition-colors">
                                        <td class="px-4 py-3 font-semibold text-white flex items-center gap-2">
                                            <template x-if="uStat.avatar_url">
                                                <img :src="uStat.avatar_url"
                                                    class="w-6 h-6 rounded-full border border-indigo-500/30 object-cover">
                                            </template>
                                            <template x-if="!uStat.avatar_url">
                                                <div class="w-6 h-6 rounded-full bg-slate-800 flex items-center justify-center text-[10px] text-slate-300 font-bold border border-slate-700"
                                                    x-text="(uStat.name || 'U').charAt(0).toUpperCase()">
                                                </div>
                                            </template>
                                            <span x-text="uStat.name"></span>
                                            <span class="text-xs text-slate-500 font-mono"
                                                x-text="'(' + uStat.email + ')'"></span>
                                        </td>
                                        <td class="px-4 py-3 text-center font-mono text-emerald-400 font-bold"
                                            x-text="uStat.total_cached"></td>
                                        <td class="px-4 py-3 text-center font-mono text-sky-400"
                                            x-text="formatBytesJS(uStat.total_bytes)"></td>
                                        <td class="px-4 py-3 text-center">
                                            <template x-if="uStat.active_downloads > 0">
                                                <span
                                                    class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30"
                                                    x-text="uStat.active_downloads + ' Aktif'"></span>
                                            </template>
                                            <template x-if="!uStat.active_downloads || uStat.active_downloads <= 0">
                                                <span class="text-xs text-slate-500">Yok</span>
                                            </template>
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-xs text-amber-300 font-bold"
                                            x-text="uStat.last_ip || 'N/A'"></td>
                                        <td class="px-4 py-3 text-right">
                                            <button @click="deleteUser(uStat.id, uStat.name)"
                                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 hover:text-rose-400 text-slate-400 text-xs transition flex items-center gap-1.5 ml-auto"
                                                title="Kullanıcıyı ve Tüm Verilerini Sil">
                                                <i class="fa-solid fa-user-xmark text-rose-400"></i> Sil
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                                <template x-if="userStatsList.length === 0">
                                    <tr>
                                        <td colspan="6" class="px-4 py-6 text-center text-slate-500 text-xs">
                                            Kullanıcı verisi bulunamadı.
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- USER STATS PAGINATION FOOTER -->
                    <template x-if="userStatsList.length > userStatsPerPage">
                        <div
                            class="px-4 py-3 border-t border-slate-800/80 bg-slate-900/60 flex items-center justify-between flex-wrap gap-3 text-xs rounded-b-xl">
                            <div class="text-slate-400">
                                Toplam <span class="font-bold text-amber-300" x-text="userStatsList.length"></span>
                                kullanıcıdan
                                <span class="font-bold text-slate-200"
                                    x-text="((userStatsPage - 1) * userStatsPerPage) + 1"></span> -
                                <span class="font-bold text-slate-200"
                                    x-text="Math.min(userStatsPage * userStatsPerPage, userStatsList.length)"></span>
                                arası gösteriliyor
                            </div>
                            <div class="flex items-center gap-2">
                                <button @click="userStatsPage--" :disabled="userStatsPage <= 1"
                                    class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1 font-semibold">
                                    <i class="fa-solid fa-chevron-left text-[10px]"></i> Önceki
                                </button>
                                <span class="px-2 text-slate-400 font-mono text-xs">
                                    Sayfa <span class="font-bold text-white" x-text="userStatsPage"></span> / <span
                                        x-text="totalUserStatsPages()"></span>
                                </span>
                                <button @click="userStatsPage++" :disabled="userStatsPage >= totalUserStatsPages()"
                                    class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1 font-semibold">
                                    Sonraki <i class="fa-solid fa-chevron-right text-[10px]"></i>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            @endif

            <!-- ACTIVE & CACHED DOWNLOADS TABLE CONTAINER -->
            <div class="glass-card rounded-2xl overflow-hidden border border-slate-800">
                <div class="p-5 border-b border-slate-800/80 flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-server text-indigo-400"></i>
                            <span>İndirme Listesi</span>
                        </h3>
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
                                <th class="px-4 py-3.5 min-w-[160px]">Dosya Adı</th>
                                <template x-if="isSuperUser">
                                    <th class="px-3 py-3.5">Kullanıcı</th>
                                </template>
                                <th class="px-3 py-3.5">Boyut</th>
                                <th class="px-3 py-3.5 min-w-[160px]">Durum & İlerleme</th>
                                <th class="px-3 py-3.5 text-center whitespace-nowrap">İndirme Sayısı</th>
                                <th class="px-4 py-3.5 text-right">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <template x-for="item in paginatedDownloadsList()" :key="item.id">
                                <tr class="hover:bg-slate-900/40 transition-colors">
                                    <!-- FILE NAME & LINK -->
                                    <td class="px-4 py-3.5">
                                        <div class="font-semibold text-white flex items-center gap-2 max-w-[200px] sm:max-w-[260px] md:max-w-[340px] truncate"
                                            :title="item.filename || 'Dosya Adı Bekleniyor...'">
                                            <i class="fa-regular fa-file-lines text-indigo-400 shrink-0"></i>
                                            <span class="truncate" x-text="item.filename || 'Dönüştürülüyor...'"></span>
                                            <template x-if="isSuperUser && item.user_count">
                                                <span
                                                    class="px-2 py-0.5 text-[10px] font-bold rounded bg-amber-500/20 text-amber-300 border border-amber-500/30 whitespace-nowrap shrink-0"
                                                    :title="'Bu dosya toplam ' + item.user_count + ' kullanıcı hesabına tanımlı'"
                                                    x-text="item.user_count + ' Kullanıcı'"></span>
                                            </template>
                                        </div>
                                    </td>

                                    <!-- SUPERUSER ONLY: USER & IP COLUMNS -->
                                    <template x-if="isSuperUser">
                                        <td class="px-3 py-3.5 text-xs font-semibold text-amber-300 whitespace-nowrap">
                                            <div class="flex items-center gap-1.5 truncate max-w-[120px]"
                                                :title="item.user?.name">
                                                <i class="fa-solid fa-user text-[10px] shrink-0"></i>
                                                <span class="truncate"
                                                    x-text="item.user?.name || 'Sistem / Superuser'"></span>
                                            </div>
                                        </td>
                                    </template>

                                    <!-- FILESIZE -->
                                    <td class="px-3 py-3.5 text-xs font-mono text-slate-300 whitespace-nowrap">
                                        <span x-text="formatBytesJS(item.filesize)"></span>
                                    </td>

                                    <!-- STATUS & PROGRESS -->
                                    <td class="px-3 py-3.5">
                                        <!-- COMPLETED STATUS -->
                                        <template x-if="item.status === 'completed'">
                                            <div class="space-y-1">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 whitespace-nowrap">
                                                    <i class="fa-solid fa-circle-check"></i> Hazır
                                                </span>
                                            </div>
                                        </template>

                                        <!-- DOWNLOADING STATUS -->
                                        <template
                                            x-if="item.status === 'downloading' || item.status === 'unrestricting' || item.status === 'pending'">
                                            <div class="space-y-1.5 min-w-[140px] max-w-[180px]">
                                                <div class="flex justify-between text-xs">
                                                    <span
                                                        class="text-indigo-400 font-medium flex items-center gap-1 truncate">
                                                        <i class="fa-solid fa-spinner animate-spin shrink-0"></i>
                                                        <span class="truncate"
                                                            x-text="item.status === 'downloading' ? 'Hazırlanıyor...' : 'Real-Debrid Bekleniyor'"></span>
                                                    </span>
                                                    <span class="font-mono text-slate-300 shrink-0 ml-1"
                                                        x-text="getProgress(item) + '%'"></span>
                                                </div>
                                                <div class="w-full bg-slate-800 rounded-full h-2 overflow-hidden">
                                                    <div class="bg-gradient-to-r from-indigo-500 to-sky-400 h-2 rounded-full transition-all duration-300"
                                                        :style="'width: ' + getProgress(item) + '%'"></div>
                                                </div>
                                                <div class="text-[11px] text-slate-400 font-mono whitespace-nowrap"
                                                    x-text="formatBytesJS(item.downloaded_bytes) + ' / ' + formatBytesJS(item.filesize)">
                                                </div>
                                            </div>
                                        </template>

                                        <!-- FAILED STATUS -->
                                        <template x-if="item.status === 'failed'">
                                            <div>
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20 whitespace-nowrap"
                                                    :title="item.error_message">
                                                    <i class="fa-solid fa-circle-xmark"></i> Hata Oluştu
                                                </span>
                                                <p class="text-[11px] text-rose-400/80 truncate mt-1 max-w-[140px]"
                                                    x-text="item.error_message"></p>
                                            </div>
                                        </template>
                                    </td>

                                    <!-- DOWNLOAD COUNT -->
                                    <td
                                        class="px-3 py-3.5 text-center text-xs font-mono font-bold text-amber-400 whitespace-nowrap">
                                        <span x-text="item.download_count"></span> x
                                    </td>

                                    <!-- ACTIONS -->
                                    <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <!-- SUPERUSER ONLY: RETRY FAILED DOWNLOAD BUTTON -->
                                            <template x-if="isSuperUser && item.status === 'failed'">
                                                <button @click="retryItem(item.uuid)"
                                                    class="px-2.5 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold shadow transition-all flex items-center gap-1"
                                                    title="Hatalı İndirmeyi Temizle ve Tekrar Dene">
                                                    <i class="fa-solid fa-rotate-right"></i> Tekrar Dene
                                                </button>
                                            </template>

                                            <!-- DIRECT PROXY DOWNLOAD BUTTON -->
                                            <template x-if="item.status === 'completed'">
                                                <a :href="'/dl/' + item.uuid" target="_blank"
                                                    class="px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold shadow transition-all flex items-center gap-1">
                                                    <i class="fa-solid fa-download"></i> İndir
                                                </a>
                                            </template>

                                            <!-- COPY PROXY LINK BUTTON -->
                                            <template x-if="item.status === 'completed'">
                                                <button @click="copyLink(window.location.origin + '/dl/' + item.uuid)"
                                                    class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition"
                                                    title="İndirme Linkini Kopyala">
                                                    <i class="fa-solid fa-copy"></i>
                                                </button>
                                            </template>

                                            <!-- COPY ORIGINAL LINK BUTTON -->
                                            <button @click="copyOriginalLink(item.original_link)"
                                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition"
                                                title="Orijinal İndirme Bağlantısını Kopyala">
                                                <i class="fa-solid fa-link"></i>
                                            </button>

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

                <!-- DOWNLOADS LIST PAGINATION FOOTER -->
                <template x-if="downloadsList.length > 0">
                    <div
                        class="px-5 py-3.5 border-t border-slate-800/80 bg-slate-900/60 flex items-center justify-between flex-wrap gap-3 text-xs">
                        <div class="flex items-center gap-3">
                            <div class="text-slate-400">
                                Toplam <span class="font-bold text-indigo-400" x-text="downloadsList.length"></span>
                                kayıttan
                                <span class="font-bold text-slate-200"
                                    x-text="((downloadsPage - 1) * downloadsPerPage) + 1"></span> -
                                <span class="font-bold text-slate-200"
                                    x-text="Math.min(downloadsPage * downloadsPerPage, downloadsList.length)"></span>
                                arası gösteriliyor
                            </div>
                            <div class="flex items-center gap-1.5 text-slate-400">
                                <label for="perPageSelect" class="text-slate-500">Sayfa Başına:</label>
                                <select id="perPageSelect" x-model.number="downloadsPerPage" @change="downloadsPage = 1"
                                    class="bg-slate-800 border border-slate-700 text-slate-200 rounded-md px-2 py-1 focus:outline-none text-xs">
                                    <option :value="5">5</option>
                                    <option :value="10">10</option>
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5" x-show="totalDownloadsPages() > 1">
                            <button @click="downloadsPage--" :disabled="downloadsPage <= 1"
                                class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1 font-semibold">
                                <i class="fa-solid fa-chevron-left text-[10px]"></i> Önceki
                            </button>

                            <template x-for="p in totalDownloadsPages()" :key="p">
                                <button @click="downloadsPage = p"
                                    class="w-7 h-7 rounded-lg text-xs font-semibold transition"
                                    :class="downloadsPage === p ? 'bg-indigo-600 text-white shadow' : 'bg-slate-800 text-slate-400 hover:bg-slate-700'"
                                    x-text="p"></button>
                            </template>

                            <button @click="downloadsPage++" :disabled="downloadsPage >= totalDownloadsPages()"
                                class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1 font-semibold">
                                Sonraki <i class="fa-solid fa-chevron-right text-[10px]"></i>
                            </button>
                        </div>
                    </div>
                </template>
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
                userStatsList: @js($userStats ?? []),
                isRefreshing: false,
                isSuperUser: {{ !empty($isSuperUser) && $isSuperUser ? 'true' : 'false' }},

                // Toast notifications state & helper
                toasts: [],

                showToast(message, type = 'info', duration = 3000) {
                    const id = Date.now() + Math.random();
                    this.toasts.push({ id, message, type });
                    setTimeout(() => {
                        this.removeToast(id);
                    }, duration);
                },

                removeToast(id) {
                    this.toasts = this.toasts.filter(t => t.id !== id);
                },

                // Pagination states
                downloadsPage: 1,
                downloadsPerPage: 10,
                userStatsPage: 1,
                userStatsPerPage: 5,

                rdInfo: {
                    loading: true,
                    success: false,
                    active_proxy: '{{ \App\Services\RealDebridService::getDisplayProxy(\App\Services\RealDebridService::getCandidateProxiesForApi()[0] ?? null) }}',
                    data: {}
                },

                paginatedUserStats() {
                    const start = (this.userStatsPage - 1) * this.userStatsPerPage;
                    return this.userStatsList.slice(start, start + this.userStatsPerPage);
                },

                totalUserStatsPages() {
                    return Math.max(1, Math.ceil(this.userStatsList.length / this.userStatsPerPage));
                },

                paginatedDownloadsList() {
                    const start = (this.downloadsPage - 1) * this.downloadsPerPage;
                    return this.downloadsList.slice(start, start + this.downloadsPerPage);
                },

                totalDownloadsPages() {
                    return Math.max(1, Math.ceil(this.downloadsList.length / this.downloadsPerPage));
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
                    const promptText = this.isSuperUser
                        ? 'Superuser Yetkisi: Bu dosyayı TÜM kullanıcılardan ve önbellekten tamamen silmek istediğinize emin misiniz?'
                        : (isRunning
                            ? 'Bu indirmeyi durdurup iptal etmek ve kaydı silmek istediğinize emin misiniz?'
                            : 'Bu önbellek dosyasını ve kaydını silmek istediğinize emin misiniz?');

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
                            this.showToast(json.message || 'İndirme kaydı başarıyla silindi.', 'success');
                            this.fetchDownloads(true);
                        } else {
                            this.showToast(json?.message || 'Silme işlemi sırasında hata oluştu.', 'error');
                        }
                    } catch (e) {
                        this.fetchDownloads(true);
                        this.showToast('İşlem sırasında hata oluştu.', 'error');
                    }
                },

                async deleteUser(userId, userName) {
                    if (!confirm(`"${userName}" kullanıcısını ve bu kullanıcıya ait TÜM veri ve dosyaları veritabanından silmek istediğinize emin misiniz?`)) return;

                    try {
                        const res = await fetch('/users/' + userId, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Accept': 'application/json'
                            }
                        });
                        const json = await res.json();
                        if (json && json.success) {
                            this.showToast(json.message || 'Kullanıcı ve verileri başarıyla silindi.', 'success');
                            this.userStatsList = this.userStatsList.filter(u => u.id !== userId);
                            this.fetchDownloads(true);
                        } else {
                            this.showToast(json?.message || 'Kullanıcı silinirken hata oluştu.', 'error');
                        }
                    } catch (e) {
                        this.showToast('Kullanıcı silinirken sunucu hatası oluştu.', 'error');
                    }
                },

                async retryItem(uuid) {
                    if (!confirm('Bu hatalı indirmeyi temizleyip yeniden başlatmak istediğinize emin misiniz?')) return;

                    try {
                        const res = await fetch('/downloads/' + uuid + '/retry', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Accept': 'application/json'
                            }
                        });
                        const json = await res.json();
                        if (json && json.success) {
                            this.showToast(json.message || 'İndirme temizlendi ve yeniden başlatıldı!', 'success');
                            this.fetchDownloads(true);
                        } else {
                            this.showToast(json?.message || 'Yeniden başlatılırken hata oluştu.', 'error');
                        }
                    } catch (e) {
                        this.showToast('Yeniden başlatma isteğinde sunucu hatası oluştu.', 'error');
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

                        if (res.status === 403 || json?.redirect) {
                            this.showToast(json?.message || 'Üyelik grubunuz yetkili olmadığı için oturumunuz kapatıldı.', 'error');
                            setTimeout(() => {
                                window.location.href = json?.redirect || '/login';
                            }, 1500);
                            return;
                        }

                        if (json && json.success) {
                            this.inputUrl = '';
                            this.showToast(json?.message || 'İndirme talebi alındı.', 'success');
                            this.fetchDownloads(true);
                        } else {
                            this.showToast(json?.message || 'İndirme kuyruğa eklenirken hata oluştu.', 'error');
                        }
                    } catch (e) {
                        this.showToast('İstek gönderilirken hata oluştu.', 'error');
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
                        this.showToast('Panoya erişilemedi.', 'error');
                    }
                },

                copyLink(text) {
                    navigator.clipboard.writeText(text);
                    this.showToast('Proxy İndirme Linki Panoya Kopyalandı!', 'success');
                },

                copyIdmLink(item) {
                    if (!item) return;
                    const idmUrl = window.location.origin + '/api/indir/' + (item.id || item.uuid);
                    navigator.clipboard.writeText(idmUrl);
                    this.showToast('IDM Uyumlu Bağlantı Kopyalandı!', 'success');
                },

                copyOriginalLink(link) {
                    if (!link) return;
                    navigator.clipboard.writeText(link);
                    this.showToast('Orijinal Bağlantı Panoya Kopyalandı!', 'success');
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