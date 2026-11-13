{{-- Customer page: connect Discord and see whether you count as a member. Uses the active theme's colours. --}}
<div class="container mt-14 max-w-2xl">
    <h1 class="text-2xl font-bold">Discord</h1>
    <p class="mt-2 text-base/70">
        {{ $t('The subscriptions are for members of our Discord. Connect your Discord account here so we can see it\'s you.') }}
    </p>

    <div class="mt-8 bg-background-secondary border border-neutral rounded-lg p-6 flex flex-col gap-5">
        @if (!$connected)
            <div>
                <p class="font-semibold">{{ $t('No Discord account connected') }}</p>
                <p class="text-sm text-base/70 mt-1">{{ $t('We only get your Discord name and ID – not your email or your servers.') }}</p>
            </div>
            <a href="{{ route('extensions.wizarr.discord.connect') }}"
                class="inline-flex w-fit items-center gap-2 rounded-md bg-primary text-white px-4 py-2.5 text-sm font-semibold hover:bg-primary/80">
                {{ $t('Connect Discord') }}
            </a>
        @else
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <p class="text-sm text-base/70">{{ $t('Connected as') }}</p>
                    <p class="font-semibold text-lg">{{ $name }}</p>
                </div>
                @if ($error)
                    <span class="rounded-full border border-neutral px-3 py-1 text-sm">{{ $t('Can\'t check Discord right now') }}</span>
                @elseif ($member)
                    <span class="rounded-full bg-success/20 text-success px-3 py-1 text-sm font-semibold">{{ $t('Member ✓') }}</span>
                @else
                    <span class="rounded-full border border-neutral text-error px-3 py-1 text-sm font-semibold">{{ $t('Not a member yet') }}</span>
                @endif
            </div>

            @if ($member === false)
                <p class="text-sm text-base/70">
                    {{ $t('Join our Discord server and come back here. Once you are a member, you can buy.') }}
                </p>
                <div class="flex gap-3 flex-wrap">
                    @if ($inviteUrl)
                        <a href="{{ $inviteUrl }}" target="_blank" rel="noopener"
                            class="inline-flex items-center rounded-md bg-primary text-white px-4 py-2.5 text-sm font-semibold hover:bg-primary/80">
                            {{ $t('Join our Discord') }}
                        </a>
                    @endif
                    <a href="{{ route('extensions.wizarr.discord') }}"
                        class="inline-flex items-center rounded-md border border-neutral px-4 py-2.5 text-sm font-semibold hover:bg-background/50">
                        {{ $t('I have joined – check again') }}
                    </a>
                </div>
            @elseif ($member)
                <a href="{{ route('home') }}"
                    class="inline-flex w-fit items-center rounded-md bg-primary text-white px-4 py-2.5 text-sm font-semibold hover:bg-primary/80">
                    {{ $t('See the subscriptions') }}
                </a>
            @endif

            <form method="POST" action="{{ route('extensions.wizarr.discord.disconnect') }}" class="border-t border-neutral pt-4">
                @csrf
                <button type="submit" class="text-sm text-base/70 hover:text-base underline cursor-pointer">{{ $t('Disconnect') }}</button>
            </form>
        @endif
    </div>
</div>
