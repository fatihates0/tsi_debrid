<!DOCTYPE html>
<html lang="tr" class="dark h-full bg-slate-950">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - TSI Debrid</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
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

<body
    class="h-full text-slate-100 bg-slate-950 font-sans antialiased selection:bg-indigo-500 selection:text-white flex items-center justify-center p-4 relative overflow-hidden">

    <!-- Ambient background glows -->
    <div
        class="absolute top-1/4 left-1/4 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none">
    </div>
    <div
        class="absolute bottom-1/4 right-1/4 translate-x-1/2 translate-y-1/2 w-96 h-96 bg-sky-500/15 rounded-full blur-3xl pointer-events-none">
    </div>

    <div class="w-full max-w-md space-y-6 relative z-10">

        <!-- LOGO & BRAND -->
        <div class="text-center space-y-3">
            <div
                class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-tr from-indigo-600 via-violet-600 to-sky-400 p-0.5 shadow-xl shadow-indigo-500/20 mb-2">
                <div class="w-full h-full bg-slate-950 rounded-[14px] flex items-center justify-center">
                    <i class="fa-solid fa-bolt text-indigo-400 text-2xl"></i>
                </div>
            </div>
            <h1 class="text-2xl font-extrabold tracking-tight text-white">
                TSI <span class="gradient-text">DEBRID</span>
            </h1>

        </div>

        <!-- LOGIN CARD -->
        <div class="glass-card rounded-2xl p-6 sm:p-8 shadow-2xl space-y-6">
            <div class="border-b border-slate-800 pb-4">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-right-to-bracket text-indigo-400"></i>
                    Kullanıcı Girişi
                </h2>
                <p class="text-xs text-slate-400 mt-1">
                    <strong class="text-slate-200">turkcesesindir.com</strong> kullanıcı adınız veya
                    e-postanız ile giriş yapın.
                </p>
            </div>

            <!-- ERROR ALERT -->
            @if ($errors->any())
                <div class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-300 text-xs space-y-1">
                    <div class="flex items-center gap-2 font-semibold text-red-200">
                        <i class="fa-solid fa-circle-exclamation text-red-400"></i>
                        Giriş Başarısız
                    </div>
                    @foreach ($errors->all() as $error)
                        <p class="pl-6">• {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if (session('status'))
                <div
                    class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-xs flex items-center gap-2">
                    <i class="fa-solid fa-circle-check text-emerald-400"></i>
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-5" x-data="{ submitting: false }"
                @submit="submitting = true">
                @csrf

                <!-- LOGIN (USERNAME OR EMAIL) -->
                <div class="space-y-1.5">
                    <label for="login" class="block text-xs font-medium text-slate-300">
                        Kullanıcı Adı veya E-posta
                    </label>
                    <div class="relative">
                        <div
                            class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                            <i class="fa-solid fa-user text-sm"></i>
                        </div>
                        <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus
                            placeholder="Kullanıcı adınız"
                            class="glass-input w-full pl-10 pr-4 py-2.5 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none transition">
                    </div>
                </div>

                <!-- PASSWORD -->
                <div class="space-y-1.5">
                    <label for="password" class="block text-xs font-medium text-slate-300">
                        Şifre
                    </label>
                    <div class="relative">
                        <div
                            class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                            <i class="fa-solid fa-lock text-sm"></i>
                        </div>
                        <input id="password" name="password" type="password" required placeholder="••••••••"
                            class="glass-input w-full pl-10 pr-4 py-2.5 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none transition">
                    </div>
                </div>

                <!-- REMEMBER ME -->
                <div class="flex items-center justify-between text-xs">
                    <label
                        class="flex items-center gap-2 cursor-pointer select-none text-slate-400 hover:text-slate-300">
                        <input type="checkbox" name="remember"
                            class="w-4 h-4 rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-slate-950">
                        <span>Beni Hatırla</span>
                    </label>
                    <a href="https://turkcesesindir.com/lost-password/" target="_blank" rel="noopener"
                        class="text-indigo-400 hover:text-indigo-300 transition">
                        Şifremi Unuttum?
                    </a>
                </div>

                <!-- SUBMIT BUTTON -->
                <button type="submit" :disabled="submitting"
                    class="w-full py-3 px-4 rounded-xl font-semibold text-sm text-white bg-gradient-to-r from-indigo-600 via-indigo-500 to-sky-500 hover:from-indigo-500 hover:to-sky-400 active:scale-[0.99] shadow-lg shadow-indigo-600/30 transition flex items-center justify-center gap-2 disabled:opacity-75">
                    <template x-if="!submitting">
                        <span class="flex items-center gap-2">
                            <i class="fa-solid fa-right-to-bracket"></i>
                            Giriş Yap
                        </span>
                    </template>
                    <template x-if="submitting">
                        <span class="flex items-center gap-2">
                            <i class="fa-solid fa-circle-notch animate-spin"></i>
                            Doğrulanıyor...
                        </span>
                    </template>
                </button>
            </form>
        </div>

        <!-- FOOTER INFO -->
        <div class="text-center text-xs text-slate-500 space-y-1">
            <p>Giriş işlemleri doğrudan <strong class="text-slate-400">turkcesesindir.com</strong> üzerinden güvenle
                doğrulanır.</p>
        </div>
    </div>

</body>

</html>