{{-- Customer page: report a problem with a movie or show. Search, choose, describe; it becomes a support ticket.
     Styled by its own CSS (the Requests tab's look), so it doesn't depend on the theme's Tailwind build. --}}
<div id="paywarr-report" class="container mt-14">
    <style>{!! $css !!}</style>

    <header class="pr-head">
        <div>
            <h1>{{ $t('Report a problem') }}</h1>
            <p class="pr-muted">{{ $t('Something wrong with a movie or show? Find it, tell us what\'s wrong, and we\'ll look into it. You\'ll get our answer as a support ticket.') }}</p>
        </div>
    </header>

    <ol class="pr-steps">
        <li class="{{ $chosen ? '' : 'pr-on' }}"><b>1</b>{{ $t('Find the title') }}</li>
        <li class="{{ $chosen ? 'pr-on' : '' }}"><b>2</b>{{ $t('Describe the problem') }}</li>
    </ol>

    @if ($error)
        <p class="pr-flash pr-error">{{ $error }}</p>
    @endif

    @if (!$chosen)
        <form method="GET" action="{{ route('extensions.wizarr.report') }}" class="pr-search">
            <input type="search" name="q" value="{{ $query }}" maxlength="100" required minlength="2" @if ($query === '') autofocus @endif
                placeholder="{{ $t('Search for a movie or show') }}">
            <button type="submit">{{ $t('Search') }}</button>
        </form>

        @if ($query !== '' && !$results)
            <p class="pr-muted" style="margin-top:1.5rem">{{ $t('Nothing found in the library. Try another spelling, or the original title.') }}</p>
        @endif

        @if ($results)
            <h2 class="pr-h">{{ $t('Choose the one with the problem:') }}</h2>
            <div class="pr-grid">
                @foreach ($results as $result)
                    <a class="pr-tile" href="{{ route('extensions.wizarr.report', ['pick' => $result['key']]) }}">
                        <span class="pr-cover">
                            <span class="pr-letter">{{ mb_strtoupper(mb_substr($result['title'] ?: '?', 0, 1)) }}</span>
                            @if ($result['poster'])
                                <img src="{{ $result['poster'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">
                            @endif
                        </span>
                        <span class="pr-title pr-clip" style="display:block" title="{{ $result['title'] }}">{{ $result['title'] }}</span>
                        <span class="pr-muted pr-tiny">{{ implode(' · ', array_filter([$result['year'], $result['kind'] === 'show' ? $t('Show') : $t('Movie')])) }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    @else
        <form method="POST" action="{{ route('extensions.wizarr.report.submit') }}" class="pr-card pr-form">
            @csrf
            <input type="hidden" name="pick" value="{{ $pick }}">

            <div class="pr-show" style="margin:0">
                <div>
                    <span class="pr-thumb pr-thumb-big">
                        <span class="pr-letter">{{ mb_strtoupper(mb_substr($chosen['title'] ?: '?', 0, 1)) }}</span>
                        @if ($chosen['poster'] ?? null)
                            <img src="{{ $chosen['poster'] }}" alt="" referrerpolicy="no-referrer" onerror="this.remove()">
                        @endif
                    </span>
                    <div style="min-width:0">
                        <div class="pr-muted pr-tiny">{{ $chosen['kind'] === 'show' ? $t('Show') : $t('Movie') }}</div>
                        <div class="pr-title pr-big">{{ $chosen['title'] }}@if ($chosen['year']) <span class="pr-muted">({{ $chosen['year'] }})</span>@endif</div>
                    </div>
                </div>
                <a class="pr-link" href="{{ route('extensions.wizarr.report') }}">{{ $t('Choose another') }}</a>
            </div>

            <div>
                <span class="pr-label">{{ $t('What is the problem?') }}</span>
                <div class="pr-choices">
                    @foreach ($problems as $value => $label)
                        @php([$head, $detail] = preg_match('/^(.*?)\s*\((.+)\)$/u', $label, $m) ? [$m[1], $m[2]] : [$label, ''])
                        <label class="pr-choice">
                            <input type="radio" name="problem" value="{{ $value }}" required>
                            <span><b>{{ $head }}</b>@if ($detail !== '')<br><span class="pr-muted pr-tiny">{{ $detail }}</span>@endif</span>
                        </label>
                    @endforeach
                </div>
            </div>

            @if ($chosen['kind'] === 'show')
                <div class="pr-fields">
                    <label>
                        <span class="pr-label">{{ $t('Season') }}</span>
                        <input class="pr-input" type="number" name="season" min="0" max="9999" inputmode="numeric">
                    </label>
                    <label>
                        <span class="pr-label">{{ $t('Episode') }}</span>
                        <input class="pr-input" type="number" name="episode" min="0" max="9999" inputmode="numeric">
                    </label>
                </div>
            @endif

            <label>
                <span class="pr-label">{{ $t('Tell us more (optional)') }}</span>
                <textarea class="pr-input" name="description" rows="4" maxlength="2000"
                    placeholder="{{ $t('E.g. the sound disappears after 20 minutes, on the TV app.') }}"></textarea>
            </label>

            <div><button type="submit" class="pr-button">{{ $t('Send report') }}</button></div>
        </form>
    @endif
</div>
