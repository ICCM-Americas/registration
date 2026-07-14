{{--
    The registrant's invoice-style cost summary, shown on the final wizard step:
    one row per line item (base charges, chosen priced options, per-diem), then
    subtotal/discount only when a discount applies, then the total. Amounts are
    formatted in the default currency ($def).

    Expects: $costSummary (CostSummary or null), $def (default Currency or null).
    Renders nothing when there is no summary or nothing is priced. Admin-facing
    text around the summary is not configurable yet — keep this partial
    self-contained so wrapping it in configurable intro/outro text stays easy.
--}}
@if (($costSummary ?? null) && ! $costSummary->isEmpty())
    @php($money = fn ($amount) => $def?->format($amount) ?? number_format((float) $amount, 2))
    <div class="card mb-3" data-cost-summary>
        <div class="card-header">{{ __('registration::common.cost_summary_heading') }}</div>
        <table class="table table-sm mb-0">
            <tbody>
                @foreach ($costSummary->lines as $line)
                    <tr>
                        <td>{{ $vars($line['label']) }}</td>
                        <td class="text-right">{{ $money($line['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                @if ($costSummary->discount)
                    <tr>
                        <td>{{ __('registration::common.cost_subtotal') }}</td>
                        <td class="text-right">{{ $money($costSummary->subtotal) }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('registration::common.cost_discount') }} ({{ $costSummary->discount->code }})</td>
                        <td class="text-right">-{{ $money($costSummary->discountAmount()) }}</td>
                    </tr>
                @endif
                <tr>
                    <th>{{ __('registration::common.cost_total') }}</th>
                    <th class="text-right">{{ $money($costSummary->total) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
