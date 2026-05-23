<div class="mx-auto max-w-5xl p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos/product') }}" wire:navigate class="hover:text-primary-700">POS Products</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">Import</span>
    </div>

    <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        {{-- Header + Template download --}}
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-chrome-900">Import products</h2>
                <p class="text-xs text-chrome-500">Upload an .xlsx or .csv file. Existing products are matched by Barcode and updated; new ones are created.</p>
            </div>
            <a href="{{ url('/app/pos/product/import/template') }}"
                class="o-btn o-btn-ghost inline-flex items-center gap-1.5 text-xs" download>
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                Download import template
            </a>
        </div>

        {{-- Stage: UPLOAD ─────────────────────────────────────────────── --}}
        @if ($stage === 'upload')
            <div class="rounded-lg border-2 border-dashed border-chrome-200 p-6">
                <input type="file" wire:model="file" accept=".xlsx,.csv"
                    class="block w-full text-sm text-chrome-600
                           file:mr-3 file:rounded-md file:border-0 file:bg-primary-700
                           file:px-4 file:py-2 file:text-sm file:font-medium file:text-white
                           hover:file:bg-primary-800">
                <div wire:loading wire:target="file" class="mt-2 text-xs text-chrome-400">Uploading…</div>
                @error('file') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror

                <p class="mt-4 text-xs text-chrome-500">
                    Expected columns: <span class="font-mono">Name</span>,
                    <span class="font-mono">Barcode</span>,
                    <span class="font-mono">Sale Price</span>,
                    <span class="font-mono">Cost Price</span>,
                    <span class="font-mono">Tax %</span>.
                    Required: <strong>Name</strong> and <strong>Sale Price</strong>.
                </p>

                <div class="mt-4 flex justify-end">
                    <button type="button" wire:click="preview"
                        @disabled(! $file)
                        class="o-btn-primary disabled:opacity-40">
                        Preview →
                    </button>
                </div>
            </div>
        @endif

        {{-- Stage: PREVIEW ─────────────────────────────────────────────── --}}
        @if ($stage === 'preview' && $result)
            @if ($result->isFatal())
                <div class="rounded-lg border border-red-200 bg-red-50 p-4">
                    <p class="text-sm font-semibold text-red-700">Cannot import this file:</p>
                    <ul class="mt-1 list-inside list-disc text-sm text-red-700">
                        @foreach ($result->fileErrors as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                    <button type="button" wire:click="restart" class="o-btn-ghost mt-3 text-xs">Choose a different file</button>
                </div>
            @else
                @php
                    $valid = $result->validRowCount();
                    $createCount = collect($result->rows)->where('action', 'create')->count();
                    $updateCount = collect($result->rows)->where('action', 'update')->count();
                @endphp

                {{-- Counter strip --}}
                <div class="mb-3 grid grid-cols-3 gap-2 text-sm">
                    <div class="rounded-lg bg-emerald-50 px-3 py-2 ring-1 ring-emerald-200">
                        <p class="text-xs text-emerald-700">Will create</p>
                        <p class="text-xl font-bold text-emerald-700">{{ $createCount }}</p>
                    </div>
                    <div class="rounded-lg bg-amber-50 px-3 py-2 ring-1 ring-amber-200">
                        <p class="text-xs text-amber-700">Will update</p>
                        <p class="text-xl font-bold text-amber-700">{{ $updateCount }}</p>
                    </div>
                    <div class="rounded-lg bg-red-50 px-3 py-2 ring-1 ring-red-200">
                        <p class="text-xs text-red-700">Will skip</p>
                        <p class="text-xl font-bold text-red-700">{{ $result->skippedCount }}</p>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-lg ring-1 ring-chrome-200">
                    <table class="w-full text-sm">
                        <thead class="bg-chrome-50 text-xs text-chrome-500">
                            <tr>
                                <th class="px-2 py-2 text-left">Row</th>
                                <th class="px-2 py-2 text-left">Action</th>
                                <th class="px-2 py-2 text-left">Name</th>
                                <th class="px-2 py-2 text-left">Barcode</th>
                                <th class="px-2 py-2 text-right">Sale</th>
                                <th class="px-2 py-2 text-right">Cost</th>
                                <th class="px-2 py-2 text-right">Tax %</th>
                                <th class="px-2 py-2 text-left">Issues</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-chrome-100">
                            @foreach ($result->rows as $row)
                                <tr class="{{ $row->action === 'skip' ? 'bg-red-50' : '' }}">
                                    <td class="px-2 py-1.5 text-chrome-400">{{ $row->rowNumber }}</td>
                                    <td class="px-2 py-1.5">
                                        @if ($row->action === 'create')
                                            <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">Create</span>
                                        @elseif ($row->action === 'update')
                                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">Update</span>
                                        @else
                                            <span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700">Skip</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1.5">{{ $row->name }}</td>
                                    <td class="px-2 py-1.5 font-mono text-xs text-chrome-500">{{ $row->barcode }}</td>
                                    <td class="px-2 py-1.5 text-right">{{ $row->salePrice !== null ? \App\Erp\Money\Currencies::format($row->salePrice) : '—' }}</td>
                                    <td class="px-2 py-1.5 text-right">{{ $row->costPrice !== null ? \App\Erp\Money\Currencies::format($row->costPrice) : '—' }}</td>
                                    <td class="px-2 py-1.5 text-right">{{ $row->taxRate !== null ? number_format($row->taxRate, 2) : '—' }}</td>
                                    <td class="px-2 py-1.5 text-xs text-red-600">
                                        @if ($row->errors)
                                            {{ implode(' · ', $row->errors) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="restart" class="o-btn-ghost">Cancel</button>
                    <button type="button" wire:click="commit"
                        @disabled($valid === 0)
                        class="o-btn-primary disabled:opacity-40">
                        Confirm import ({{ $valid }} {{ \Illuminate\Support\Str::plural('row', $valid) }})
                    </button>
                </div>
            @endif
        @endif

        {{-- Stage: DONE ─────────────────────────────────────────────────── --}}
        @if ($stage === 'done' && $result)
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-5 text-center">
                <p class="text-lg font-bold text-emerald-700">Import complete.</p>
                <p class="mt-1 text-sm text-emerald-700">
                    {{ $result->createdCount }} created · {{ $result->updatedCount }} updated · {{ $result->skippedCount }} skipped
                </p>
                <div class="mt-4 flex justify-center gap-2">
                    <button type="button" wire:click="restart" class="o-btn-ghost">Import another file</button>
                    <a href="{{ url('/app/pos/product') }}" wire:navigate class="o-btn-primary">Back to products</a>
                </div>
            </div>
        @endif
    </div>
</div>
