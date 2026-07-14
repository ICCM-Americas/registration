{{--
    An invited group member's split cost summary, shown on the final wizard
    step: what the group leader covers (the flat base charges) versus what
    the member owes themselves (their own priced options, guest costs,
    per-diem) — kept in two clearly separate cards so it's unambiguous what
    the leader is responsible for.

    Expects: $memberCostSummary (GroupMemberCostSummary or null), $def
    (default Currency or null). Renders nothing when there is no summary or
    nothing is priced on either side.
--}}
@if (($memberCostSummary ?? null) && ! $memberCostSummary->isEmpty())
    @php($money = fn ($amount) => $def?->format($amount) ?? number_format((float) $amount, 2))
    <div class="card mb-3" data-cost-summary-leader>
        <div class="card-header">{{ __('registration::common.group_leader_covers_heading') }}</div>
        <table class="table table-sm mb-0">
            <tbody>
                @foreach ($memberCostSummary->leaderLines as $line)
                    <tr>
                        <td>{{ $vars($line['label']) }}</td>
                        <td class="text-right">{{ $money($line['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th>{{ __('registration::common.group_leader_covers_total') }}</th>
                    <th class="text-right">{{ $money($memberCostSummary->leaderTotal) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="card mb-3" data-cost-summary-member>
        <div class="card-header">{{ __('registration::common.your_responsibility_heading') }}</div>
        <table class="table table-sm mb-0">
            <tbody>
                @foreach ($memberCostSummary->memberLines as $line)
                    <tr>
                        <td>{{ $vars($line['label']) }}</td>
                        <td class="text-right">{{ $money($line['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                @if ($memberCostSummary->discount)
                    <tr>
                        <td>{{ __('registration::common.cost_subtotal') }}</td>
                        <td class="text-right">{{ $money($memberCostSummary->memberSubtotal) }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('registration::common.cost_discount') }} ({{ $memberCostSummary->discount->code }})</td>
                        <td class="text-right">-{{ $money($memberCostSummary->discountAmount()) }}</td>
                    </tr>
                @endif
                <tr>
                    <th>{{ __('registration::common.cost_total') }}</th>
                    <th class="text-right">{{ $money($memberCostSummary->memberTotal) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
