{{-- Customer page: report a problem with a movie or show. Search, choose, describe; it becomes a support ticket. Uses the active theme's colours. --}}
<div class="container mt-14 max-w-3xl">
    <h1 class="text-2xl font-bold">{{ $t('Report a problem') }}</h1>
    <p class="mt-2 text-base/70">
        {{ $t('Something wrong with a movie or show? Find it, tell us what\'s wrong, and we\'ll look into it. You\'ll get our answer as a support ticket.') }}
    </p>

    @if ($error)
        <p class="mt-6 rounded-lg border border-error text-error px-4 py-3 text-sm">{{ $error }}</p>
    @endif

    @if (!$chosen)
        <form method="GET" action="{{ route('extensions.wizarr.report') }}" class="mt-8 flex gap-3">
            <input type="search" name="q" value="{{ $query }}" maxlength="100" required minlength="2" autofocus
                placeholder="{{ $t('Search for a movie or show') }}"
                class="flex-1 min-w-0 rounded-md border border-neutral bg-background-secondary px-4 py-2.5 text-base focus:outline-none focus:border-primary">
            <button type="submit" class="rounded-md bg-primary text-white px-4 py-2.5 text-sm font-semibold hover:bg-primary/80">{{ $t('Search') }}</button>
        </form>

        @if ($query !== '' && !$results)
            <p class="mt-6 text-base/70">{{ $t('Nothing found in the library. Try another spelling, or the original title.') }}</p>
        @endif

        @if ($results)
            <p class="mt-6 text-sm text-base/70">{{ $t('Choose the one with the problem:') }}</p>
            <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                @foreach ($results as $result)
                    <a href="{{ route('extensions.wizarr.report', ['pick' => $result['key']]) }}"
                        class="group rounded-lg border border-neutral bg-background-secondary overflow-hidden hover:border-primary">
                        <div class="relative aspect-[2/3] bg-neutral/40 flex items-center justify-center text-3xl font-bold text-base/40">
                            {{ mb_strtoupper(mb_substr($result['title'] ?: '?', 0, 1)) }}
                            @if ($result['poster'])
                                <img src="{{ $result['poster'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()" class="absolute inset-0 w-full h-full object-cover">
                            @endif
                        </div>
                        <div class="p-3">
                            <p class="font-semibold text-sm truncate group-hover:text-primary">{{ $result['title'] }}</p>
                            <p class="text-xs text-base/60">{{ implode(' · ', array_filter([$result['year'], $result['kind'] === 'show' ? $t('Show') : $t('Movie')])) }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    @else
        <form method="POST" action="{{ route('extensions.wizarr.report.submit') }}" class="mt-8 bg-background-secondary border border-neutral rounded-lg p-6 flex flex-col gap-5">
            @csrf
            <input type="hidden" name="pick" value="{{ $pick }}">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-base/70">{{ $chosen['kind'] === 'show' ? $t('Show') : $t('Movie') }}</p>
                    <p class="font-semibold text-lg">{{ $chosen['title'] }}@if ($chosen['year']) ({{ $chosen['year'] }})@endif</p>
                </div>
                <a href="{{ route('extensions.wizarr.report') }}" class="text-sm text-primary hover:underline">{{ $t('Choose another') }}</a>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold">{{ $t('What is the problem?') }}</span>
                <select name="problem" required class="rounded-md border border-neutral bg-background px-3 py-2.5">
                    <option value="" disabled selected>{{ $t('Choose') }}</option>
                    @foreach ($problems as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            @if ($chosen['kind'] === 'show')
                <div class="grid grid-cols-2 gap-4">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-semibold">{{ $t('Season') }}</span>
                        <input type="number" name="season" min="0" max="9999" class="rounded-md border border-neutral bg-background px-3 py-2.5">
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-semibold">{{ $t('Episode') }}</span>
                        <input type="number" name="episode" min="0" max="9999" class="rounded-md border border-neutral bg-background px-3 py-2.5">
                    </label>
                </div>
            @endif

            <label class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold">{{ $t('Tell us more (optional)') }}</span>
                <textarea name="description" rows="4" maxlength="2000"
                    placeholder="{{ $t('E.g. the sound disappears after 20 minutes, on the TV app.') }}"
                    class="rounded-md border border-neutral bg-background px-3 py-2.5"></textarea>
            </label>

            <button type="submit" class="w-fit rounded-md bg-primary text-white px-4 py-2.5 text-sm font-semibold hover:bg-primary/80">{{ $t('Send report') }}</button>
        </form>
    @endif
</div>
