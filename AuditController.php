<?php

namespace App\Http\Controllers;

use App\Events\AuditAssigned;
use App\Events\AuditCreated;
use App\Events\AuditorRefused;
use App\Events\AuditUpdated;
use App\Http\Requests\StoreAuditRequest;
use App\Http\Requests\UpdateAuditRequest;
use App\Mail\ClientInvoicePDF;
use App\Models\Audit;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spipu\Html2Pdf\Html2Pdf;

class AuditController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request) {
        $this->authorize('viewAny', Audit::class);
        $user = $request->user();
        $company = $user->company;
        $meta = $company->meta ?? (object) [];
        $companyId = $user->company_id;
        $search = "%{$request->search}%";

        $ap = ($meta->application_number_prefix ?? 'AP');
        $singlePrefix = ($meta->paired_audit_client_invoice_prefix ?? 'FSIN');
        $pairPrefix = ($meta->single_audit_client_invoice_prefix ?? 'SIN');
        $invPrefix = ($meta->auditor_invoice_prefix ?? 'INV');
        $singleInvSearch = preg_replace("/^{$singlePrefix}/i", '', $request->search);
        $pairedInvSearch = preg_replace("/^{$pairPrefix}/i", '', $request->search);
        $invSearch = preg_replace("/^{$invPrefix}/i", '', $request->search);

        $query = Audit::query()
            ->with('client', 'company', 'invoice')
            ->withAggregate('auditor', 'prefix_code')
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(
                    fn ($q) => $q
                        ->orWhere('invoice_number', $singleInvSearch)
                        ->orWhere('invoice_number', $pairedInvSearch)
                        ->orWhere('invoice_number', $invSearch)
                        ->orWhereRelation('invoice', 'number', 'LIKE', "%{$invSearch}%")
                        ->orLike('number', $request->search)
                        ->orLike('invoice_number', $request->search)
                        ->orLike('payment_details->chequeNumber', $request->search)
                        ->orLike('payment_details->creditCardTrackingNumber', $request->search)
                        ->orWhere(
                            fn ($q) => $q
                                ->whereRelation('auditor', 'prefix_code', '=', substr($request->search, 0, 4))
                                ->whereMode(substr($request->search, 4, 1))
                                ->whereNumber(substr($request->search, 5))
                        )

                        // Client
                        ->orWhereHas('client', fn ($w) => $w->whereRaw("concat(firstname, ' ', lastname) like ?", $search))
                        ->orWhereRelation('client', 'address->city', 'LIKE', $search)
                        ->orWhereRelation('client', 'address->postalcode', 'LIKE', $search)
                        ->orWhereRelation('client', 'address->street', 'LIKE', $search)
                        ->orWhereRelation('client', 'address->province', 'LIKE', $search)
                        ->orWhereRelation('client', 'address', 'LIKE', $search)
                        ->orWhereRelation('client', 'email', 'LIKE', $search)
                        ->orWhereRelation('client', 'phone', 'LIKE', $search)
                        ->orWhereRelation('client', 'mobile', 'LIKE', $search)
                        ->orWhereRelation('client', 'application_number', 'LIKE', $search)
                        ->orWhereRelation('client', 'application_number', 'LIKE', substr($request->search, strlen($ap)))
                )
            )
            ->when($request->filled('filters'), function($q) use ($request): void {
                if ($request->filled('filters.status')) {
                    $q->whereStatus($request->filters['status']);
                }
                if ($request->filled('filters.company')) {
                    $q->whereCompanyId($request->filters['company']);
                }
                if ($request->filled('filters.lead_generator_id')) {
                    $q->whereLeadGeneratorId($request->filters['lead_generator_id']);
                }
                if ($request->filled('filters.auditor_id')) {
                    $q->whereAuditorId($request->filters['auditor_id']);
                }
                if ($request->filled('filters.mode')) {
                    $q->whereMode($request->filters['mode']);
                }
                if ($request->filled('filters.source')) {
                    $q->whereSource($request->filters['source']);
                }
                if ($request->filled('filters.from_date')) {
                    $q->whereDate('created_at', '>=', $request->filters['from_date']);
                }
                if ($request->filled('filters.to_date')) {
                    $q->whereDate('created_at', '<=', $request->filters['to_date']);
                }
                if ($request->filled('filters.invoice_issued')) {
                    $q->where(
                        function($q) use ($request): void {
                            if ('true' === $request->filters['invoice_issued']) {
                                $q
                                    ->where('manual_payment_data->invoice_issued', true)
                                    ->orWhereHas('invoice')
                                ;
                            } else {
                                $q
                                    ->where(function($x): void {
                                        $x->where('manual_payment_data->invoice_issued', false)->orWhereNull('manual_payment_data->invoice_issued');
                                    })
                                    ->doesntHave('invoice')
                                ;
                            }
                        }
                    );
                }
            })
            ->when($user->hasRole('admin'), fn ($q) => $q->with('company.user', 'creator', 'leadGenerator', 'auditor'))
            ->when(!$user->hasRole('admin'), function($q) use ($user, $companyId): void {
                if ($user->is_superuser) {
                    $q->whereCompanyId($companyId)->with('creator', 'leadGenerator', 'auditor');
                } else {
                    $q->where(
                        fn ($qu) => $qu->whereCreatorId($user->id)->orWhere('lead_generator_id', $user->id)->orWhere('auditor_id', $user->id)
                    );
                }
            })
            ->when($request->filled('sort_by'), function($q) use ($request): void {
                $sortable = [
                    'number',
                    'auditor',
                    'client',
                    'date',
                    'credit',
                    'status',
                    'created_at',
                    'updated_at',
                    'audits_count',
                ];

                $except = ['auditor', 'client'];

                $sortBy = strtolower($request->sort_by);
                $sort = strtolower($request->sort) ?: 'asc';

                if (in_array($sortBy, $sortable)) {
                    if (!in_array($sortBy, $except)) {
                        if ('asc' === $sort) {
                            $q->orderBy($sortBy);
                        } else {
                            $q->orderByDesc($sortBy);
                        }
                    } else {
                        if ('auditor' === $sortBy) {
                            $q->withAggregate('auditor', 'firstname');
                            $q->withAggregate('auditor', 'lastname');
                            $q->orderByRaw('CONCAT(auditor_firstname, " ", auditor_lastname) '.$sort);
                        }
                        if ('client' === $sortBy) {
                            $q->withAggregate('client', 'firstname');
                            $q->withAggregate('client', 'lastname');
                            $q->orderByRaw('CONCAT(client_firstname, " ", client_lastname) '.$sort);
                        }
                    }
                }
            })
            ->where('status', '!=', 'booked')
        ;

        $auditsSample = $query->latest()->paginate();

        $clients = $auditsSample->map(function($audit) {
            return $audit->client_id;
        })->unique()->filter(fn ($e) => !empty($e));

        $pairs = Audit::whereIn('client_id', $clients->toArray())->get();

        $audits = $query->latest()->paginate()->withQueryString();

        $auditsSample->map(function($audit) use ($pairs) {
            $audit->has_pair = $pairs
                ->where('pair', $audit->pair)
                ->where('client_id', $audit->client_id)
                ->where('mode', 'E' === $audit->mode ? 'D' : 'E')
                ->first() ?: false
            ;

            // if($audit->has_pair) {
            // $audit->has_pair = $audit->has_pair->id;
            // }

            return $audit;
        });

        $params = ['audits' => $audits, 'pairs' => $auditsSample->pluck('has_pair', 'id')];

        $assignedAuditsCount = Audit::query()
            ->whereStatus('assigned')
            ->when(!$user->hasRole('admin'), function($query) use ($user): void {
                if ($user->is_superuser) {
                    $query->whereCompanyId($user->company_id);
                } else {
                    $query->whereAuditorId($user->id);
                }
            })
            ->count()
        ;

        $counters = [
            'assigned' => $assignedAuditsCount,
        ];

        $params['counters'] = $counters;

        return inertia('Audits/index', $params);
    }

    /**
     * Display a listing of the resource in calendar.
     *
     * @return \Illuminate\Http\Response
     */
    public function calendar(Request $request) {
        $this->authorize('viewAny', Audit::class);
        $user = $request->user();
        $subscriptionLink = URL::signedRoute('audits.calendar.subscribe', $user);

        $assignedAuditsCount = Audit::query()
            ->whereStatus('assigned')
            ->when(!$user->hasRole('admin'), function($query) use ($user): void {
                if ($user->is_superuser) {
                    $query->whereCompanyId($user->company_id);
                } else {
                    $query->whereAuditorId($user->id);
                }
            })
            ->count()
        ;

        $params = [
            'subscriptionLink' => $subscriptionLink,
            'counters' => [
                'assigned' => $assignedAuditsCount,
            ],
        ];

        return inertia('Audits/Calendar', $params);
    }

    public function calendarAudits(Request $request) {
        $date = $request->date ?: now()->toDateString();
        $user = $request->user();
        $carbon = new \Carbon\Carbon($date);

        if ($user->is_admin) {
            $audits = Audit::query();
        } elseif ($user->is_superuser) {
            $audits = $user->company->audits();
        } else {
            $audits = $user->audits();
        }

        return $audits
            ->with('client', 'auditor')
            ->whereDate('date', '>=', $carbon->startOfMonth())
            ->whereDate('date', '<=', $carbon->endOfMonth())
            ->where('status', '!=', 'booked')
            ->get()
        ;
    }

    public function iCal(Request $request, User $user) {
        if (!$request->hasValidSignature()) {
            return abort(404);
        }

        $filename = sprintf('%s-%s', $user->fullname, Str::random(16));
        $filename = Str::of($filename)->slug()->toString();
        $filename .= '.ics';

        $audits = $user
            ->audits()
            ->with('client')
            ->cursor()
        ;

        return response()->streamDownload(function() use ($audits, $user) {
            $handle = fopen('php://output', 'w');
            $appUrl = env('APP_URL');
            $calendarName = sprintf('%s-%s', $user->prefix_code, $user->fullname);
            $calendarDescription = sprintf('%s assigned audits (%s)', $user->fullname, optional($user->company)->name ?: env('APP_NAME'));
            $tz = optional($user)->timezone ?? env('APP_TIMEZONE', 'UTC');

            $contents = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                "PRODID:{$appUrl}",
                'METHOD:PUBLISH',
                'CALSCALE:GREGORIAN',
                'X-MICROSOFT-CALSCALE:GREGORIAN',
                "X-WR-CALNAME:{$calendarName}",
                "X-WR-CALDESC:{$calendarDescription}",
                "X-WR-TIMEZONE:{$tz}",
                'X-PUBLISHED-TTL:PT6H',
            ];
            $contents = join("\n", $contents);

            fwrite($handle, $contents);

            $audits->each(function($audit) use ($handle) {
                fwrite($handle, "\n");

                $id = $audit->id;

                $summary = sprintf('%s-Audit #%s (%s)', $audit->mode, $audit->pair, $audit->client->fullname);
                $description = "Audit Number: {$audit->audit_number}";

                $description .= "\\n\\nClient:\\n{$audit->client->fullname}\\n{$audit->client->masked_mobile}\\n{$audit->client->email}";

                if (!empty($audit->notes)) {
                    $description .= "\\n\\nNotes: {$audit->notes}";
                }

                if (!empty($audit->booking_notes)) {
                    $description .= "\\nBooking Notes: {$audit->booking_notes}";
                }

                $location = $audit->client->formatted_address;
                $date = explode(' ', $audit->date);
                $date = array_shift($date);

                $from = $audit->booking_from ? $audit->booking_from->format('H:i') : '08:00';
                $to = $audit->booking_to ? $audit->booking_to->format('H:i') : '12:00';

                $start = Carbon::parse(sprintf('%s %s', $date, $from))->format('Ymd\\THis');
                $end = Carbon::parse(sprintf('%s %s', $date, $to))->format('Ymd\\THis');
                $created = Carbon::parse($audit->created_at)->format('Ymd\\THis');
                $updated = Carbon::parse($audit->updated_at)->format('Ymd\\THis');

                $status = strtoupper($audit->status);
                $url = route('audits.update', $audit);

                $auditContent = [
                    'BEGIN:VEVENT',
                    "UID:{$id}",
                    'SEQUENCE:0',
                    'TRANSP:OPAQUE',
                    "STATUS:{$status}",
                    "URL:{$url}",
                    "SUMMARY:{$summary}",
                    'CLASS:PUBLIC',
                    "LOCATION:{$location}",
                    "DESCRIPTION:{$description}",
                    "DTSTAMP:{$created}",
                    "DTSTART:{$start}",
                    "DTEND:{$end}",
                    "CREATED:{$created}",
                    "LAST-MODIFIED:{$updated}",
                    'END:VEVENT',
                ];

                fwrite($handle, join("\n", $auditContent));

                fwrite($handle, "\n");
            });

            fwrite($handle, 'END:VCALENDAR');
        }, $filename);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function payments(Request $request) {
        $this->authorize('viewAnyPayment', Audit::class);
        $user = $request->user();
        $company = $user->company;
        $meta = $company->meta ?? (object) [];
        $companyId = $user->company_id;
        $search = "%{$request->search}%";

        $ap = ($meta->application_number_prefix ?? 'AP');
        $singlePrefix = ($meta->paired_audit_client_invoice_prefix ?? 'FSIN');
        $pairPrefix = ($meta->single_audit_client_invoice_prefix ?? 'SIN');
        $invPrefix = ($meta->auditor_invoice_prefix ?? 'INV');
        $singleInvSearch = preg_replace("/^{$singlePrefix}/i", '', $request->search);
        $pairedInvSearch = preg_replace("/^{$pairPrefix}/i", '', $request->search);
        $invSearch = preg_replace("/^{$invPrefix}/i", '', $request->search);

        $query = Audit::query()
            ->with('client', 'invoice', 'receiver')
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(
                    fn ($q) => $q
                        ->orWhere('invoice_number', $singleInvSearch)
                        ->orWhere('invoice_number', $pairedInvSearch)
                        ->orWhere('invoice_number', $invSearch)
                        ->orWhereRelation('invoice', 'number', 'LIKE', "%{$invSearch}%")
                        ->orLike('number', $request->search)
                        ->orLike('invoice_number', $request->search)
                        ->orLike('payment_details->chequeNumber', $request->search)
                        ->orLike('payment_details->creditCardTrackingNumber', $request->search)
                        ->orWhere(
                            fn ($q) => $q
                                ->whereRelation('auditor', 'prefix_code', '=', substr($request->search, 0, 4))
                                ->whereMode(substr($request->search, 4, 1))
                                ->whereNumber(substr($request->search, 5))
                        )

                        // Client
                        ->orWhereHas('client', function($w) use ($search): void {
                            $w->whereRaw("concat(firstname, ' ', lastname) like ?", $search);
                        })
                        ->orWhereRelation('client', 'application_number', 'LIKE', $search)
                        ->orWhereRelation('client', 'application_number', 'LIKE', substr($request->search, strlen($ap)))
                        ->orWhereRelation('client', 'address->city', 'LIKE', $search)
                        ->orWhereRelation('client', 'address->postalcode', 'LIKE', $search)
                        ->orWhereRelation('client', 'address->street', 'LIKE', $search)
                        ->orWhereRelation('client', 'address->province', 'LIKE', $search)
                        ->orWhereRelation('client', 'address', 'LIKE', $search)
                        ->orWhereRelation('client', 'email', 'LIKE', $search)
                        ->orWhereRelation('client', 'phone', 'LIKE', $search)
                        ->orWhereRelation('client', 'mobile', 'LIKE', $search)
                )
            )
            ->when($request->filled('filters'), function($q) use ($request): void {
                // if ($request->filled('filters.status')) {
                //     $q->whereStatus($request->filters['status']);
                // }
                if ($request->filled('filters.company')) {
                    $q->whereCompanyId($request->filters['company']);
                }
                if ($request->filled('filters.lead_generator_id')) {
                    $q->whereLeadGeneratorId($request->filters['lead_generator_id']);
                }
                if ($request->filled('filters.auditor_id')) {
                    $q->whereAuditorId($request->filters['auditor_id']);
                }
                if ($request->filled('filters.mode')) {
                    $q->whereMode($request->filters['mode']);
                }
                if ($request->filled('filters.paid_to')) {
                    $q->wherePaidTo($request->filters['paid_to']);
                }
                if ($request->filled('filters.paid_by')) {
                    $q->wherePaidBy($request->filters['paid_by']);
                }
                if ($request->filled('filters.received_by_company')) {
                    $q->when('true' === $request->filters['received_by_company'], function($r) {
                        $r->whereReceivedByCompany(1);
                    }, function($r) {
                        $r->where(function($cr) {
                            $cr->whereReceivedByCompany(0)->orWhereNull('received_by_company');
                        });
                    });
                }
                if ($request->filled('filters.invoice_issued')) {
                    $q->where(
                        function($q) use ($request): void {
                            if ('true' === $request->filters['invoice_issued']) {
                                $q
                                    ->where('manual_payment_data->invoice_issued', true)
                                    ->orWhereHas('invoice')
                                ;
                            } else {
                                $q
                                    ->where(function($x): void {
                                        $x->where('manual_payment_data->invoice_issued', false)->orWhereNull('manual_payment_data->invoice_issued');
                                    })
                                    ->doesntHave('invoice')
                                ;
                            }
                        }
                    );
                }
                if ($request->filled('filters.invoice_paid')) {
                    $q->where(
                        function($q) use ($request): void {
                            if ('true' === $request->filters['invoice_paid']) {
                                $q->whereHas('invoice', function($i) {
                                    $i->whereStatus('paid');
                                });
                            } else {
                                $q->whereHas('invoice', function($i) {
                                    $i->whereStatus('pending');
                                });
                            }
                        }
                    );
                }
                if ($request->filled('filters.from_date')) {
                    $q->whereDate('created_at', '>=', $request->filters['from_date']);
                }
                if ($request->filled('filters.to_date')) {
                    $q->whereDate('created_at', '<=', $request->filters['to_date']);
                }
            })
            ->when($user->hasRole('admin'), fn ($q) => $q->with('company.user', 'creator', 'leadGenerator', 'auditor'))
            ->when(!$user->hasRole('admin'), function($q) use ($user, $companyId): void {
                if ($user->is_superuser) {
                    $q->whereCompanyId($companyId)->with('creator', 'leadGenerator', 'auditor');
                } else {
                    $q->where(
                        fn ($qu) => $qu->whereCreatorId($user->id)->orWhere('lead_generator_id', $user->id)->orWhere('auditor_id', $user->id)
                    );
                }
            })
            ->whereStatus('closed')
            ->when($request->filled('sort_by'), function($q) use ($request): void {
                $sortable = [
                    'number',
                    'auditor',
                    'client',
                    'date',
                    'credit',
                    'commission',
                    'received',
                    'invoice_number',
                    'invoice_paid',
                ];

                $except = ['auditor', 'client', 'invoice_number', 'invoice_paid', 'received'];

                $sortBy = strtolower($request->sort_by);
                $sort = strtolower($request->sort) ?: 'asc';

                if (in_array($sortBy, $sortable)) {
                    if (!in_array($sortBy, $except)) {
                        if ('asc' === $sort) {
                            $q->orderBy($sortBy);
                        } else {
                            $q->orderByDesc($sortBy);
                        }
                    } else {
                        if ('received' === $sortBy) {
                            if ('asc' === $sort) {
                                $q->orderBy('received_by_company')->orderBy('manual_payment_data->received_by_company');
                            } else {
                                $q->orderByDesc('received_by_company')->orderByDesc('manual_payment_data->received_by_company');
                            }
                        }
                        if ('auditor' === $sortBy) {
                            $q->withAggregate('auditor', 'firstname');
                            $q->withAggregate('auditor', 'lastname');
                            $q->orderByRaw('CONCAT(auditor_firstname, " ", auditor_lastname) '.$sort);
                        }
                        if ('client' === $sortBy) {
                            $q->withAggregate('client', 'firstname');
                            $q->withAggregate('client', 'lastname');
                            $q->orderByRaw('CONCAT(client_firstname, " ", client_lastname) '.$sort);
                        }
                        if ('invoice_number' === $sortBy) {
                            $q->withAggregate('invoice', 'number');
                            $q->orderByRaw('invoice_number '.$sort);
                        }
                        if ('invoice_paid' === $sortBy) {
                            $q->withAggregate('invoice', 'status');
                            $q->orderByRaw('invoice_status '.$sort);
                        }
                    }
                }
            })
        ;

        $auditsSample = $query->latest()->limit(15)->get();

        $clients = $auditsSample->map(function($audit) {
            return $audit->client_id;
        })->unique()->filter(fn ($e) => !empty($e));

        $pairs = Audit::whereIn('client_id', $clients->toArray())->get();

        $audits = $query->latest()->paginate()->withQueryString();

        $auditsSample->map(function($audit) use ($pairs) {
            $audit->has_pair = $pairs->where('pair', $audit->pair)->where('client_id', $audit->client_id)->where('mode', 'E' === $audit->mode ? 'D' : 'E')->first() ?: false;

            if ($audit->has_pair) {
                $audit->has_pair = $audit->has_pair->id;
            }

            return $audit;
        });

        $params = ['audits' => $audits, 'pairs' => $auditsSample->pluck('has_pair', 'id')];

        $assignedAuditsCount = Audit::query()
            ->whereStatus('assigned')
            ->when(!$user->hasRole('admin'), function($query) use ($user): void {
                if ($user->is_superuser) {
                    $query->whereCompanyId($user->company_id);
                } else {
                    $query->whereAuditorId($user->id);
                }
            })
            ->count()
        ;

        $counters = [
            'assigned' => $assignedAuditsCount,
        ];

        $params['counters'] = $counters;

        return inertia('Accounting/Payments/index', $params);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request) {
        $this->authorize('create', Audit::class);
        $user = auth()->user();
        $companyId = $user->company_id;

        if ($request->filled('pair')) {
            $pair = Audit::with('client.company', 'company', 'auditor', 'leadGenerator')->findOrFail($request->pair);
            $this->authorize('view', $pair);

            if (!$pair) {
                return redirect()->route('audits.index')->with([
                    'flash' => [
                        'type' => 'danger',
                        'message' => 'Selected audit does not exists to add pair for',
                    ],
                ]);
            }

            if ($pair->hasPair()) {
                return redirect()->route('audits.index')->with([
                    'flash' => [
                        'type' => 'danger',
                        'message' => 'Selected audit already has a pair',
                    ],
                ]);
            }

            $date = $pair->date;
            $date = new Carbon($date);
            $date = $date->addDays(10);

            if ($date->isBefore(now())) {
                $date = now();
            }

            $pair->date = $date;

            $pair->client->load('request');
            $client = $pair->client;
        }

        $clients = Client::with('leadGenerator', 'company', 'request')->when(!$user->hasRole('admin'), function($q) use ($user, $companyId): void {
            if ($user->is_superuser) {
                if (!empty($companyId)) {
                    $q->whereCompanyId($companyId);
                }
            } else {
                $q->where(
                    fn ($qu) => $qu->whereUserId($user->id)->orWhere('lead_generator_id', $user->id)->orWhereHas('audits', fn ($a) => $a->where('auditor_id', $user->id))
                );
            }
        })->latest('created_at')->limit(50)->get();

        $client = $client ?? session('client');

        return inertia('Audits/Create', [
            'clients' => $clients,
            'client' => $client ?? null,
            'pairing' => $request->filled('pair'),
            'sample' => $pair ?? null,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(StoreAuditRequest $request) {
        $this->authorize('create', Audit::class);
        $user = $request->user();
        $auditor = $user;
        $companyId = $auditor->company_id || 1;

        if ($user->hasRole(['admin', 'manager'])) {
            $auditor = User::find($request->auditor_id);
        }

        $auditorId = $auditor->id;
        $creatorId = $user->id;
        $pair = 1;
        $oppositeMode = 'D' === $request->mode ? 'E' : 'D';
        $clientAudits = Audit::whereClientId($request->client_id)->get();

        if (!empty($request->number)) {
            $number = $request->number;
            $pairedAudit = $clientAudits->where('auditor_id', $auditorId)->where('mode', $oppositeMode)->where('number', $request->number)->first();

            if ($pairedAudit) {
                $pair = $pairedAudit->pair;
            } else {
                $latestAudit = $clientAudits->sortByDesc('pair')->first();
                if ($latestAudit) {
                    $pair = $latestAudit->pair + 1;
                }
            }
        } else {
            if ($clientAudits->count() < 1) {
                $pair = 1;

                if ('D' === $request->mode) {
                    $number = null;
                    $lastAudit = Audit::whereAuditorId($auditor->id)->where('status', '!=', 'booked')->where('number', '!=', '')->where('number', '<', '90000')->latest('number')->first();

                    if ($lastAudit) {
                        $number = (isset($lastAudit->number) ? $lastAudit->number : 0) + 1;
                    }
                } else {
                    $number = 90001;
                    $lastAudit = Audit::whereAuditorId($auditor->id)->where('status', '!=', 'booked')->where('number', '!=', '')->where('number', '>', '90000')->latest('number')->first();

                    if ($lastAudit) {
                        $number = (isset($lastAudit->number) ? $lastAudit->number : 0) + 1;
                    }
                }
            } else {
                $latestPair = $clientAudits->sortByDesc('pair')->first()->pair;
                $foundPair = null;

                for ($i = 1; $i <= $latestPair; ++$i) {
                    $op = $clientAudits->where('pair', $i)->mode($oppositeMode);
                    $paired = $clientAudits->where('pair', $i)->mode($request->mode);

                    if (null === $op && null === $paired) {
                        $foundPair = $i;

                        break;
                    }

                    if (!$paired) {
                        $foundPair = $i;
                        if (isset($op->auditor_id) && $op->auditor_id === $auditorId) {
                            $number = $op->number;
                        } else {
                            $lastAudit = Audit::whereAuditorId($auditor->id)->where('number', '!=', '')->where('number', '>', '90000')->latest('number')->first();
                            if ($lastAudit) {
                                $number = (isset($lastAudit->number) ? $lastAudit->number : 0) + 1;
                            } else {
                                $number = 90001;
                            }
                        }
                    }
                }

                if (null === $foundPair) {
                    $pair = $latestPair + 1;
                } else {
                    $pair = $foundPair;
                }
            }

            if (empty($number)) {
                if ('D' === $request->mode) {
                    $lastAudit = Audit::whereAuditorId($auditor->id)->where('number', '!=', '')->where('number', '<', '90000')->latest('number')->first();
                    if ($lastAudit) {
                        $number = (isset($lastAudit->number) ? $lastAudit->number : 0) + 1;
                    } else {
                        $number = 1;
                    }
                } else {
                    $lastAudit = Audit::whereAuditorId($auditor->id)->where('number', '!=', '')->where('number', '>', '90000')->latest('number')->first();
                    if ($lastAudit) {
                        $number = (isset($lastAudit->number) ? $lastAudit->number : 0) + 1;
                    } else {
                        $number = 90001;
                    }
                }
            }
        }

        $total = 0;
        $total += ($request->costs['utility'] ?? 0);
        $total += ($request->costs['cghg'] ?? 0);
        $total += ($request->costs['her'] ?? 0);
        $total += ($request->costs['gas'] ?? 0);
        $total += ($request->costs['drone'] ?? 0);
        $total += ($request->costs['infrared'] ?? 0);

        foreach ($request->extra_costs as $cost) {
            $total += $cost['qty'] * $cost['price'];
        }

        $invoiceNumber = 1;
        $lastInvoice = Audit::whereCompanyId($auditor->company_id)->where('invoice_number', '!=', '')->latest('invoice_number')->first();
        $invoiceNumber = $lastInvoice ? $lastInvoice->invoice_number + 1 : 0;
        $invoiceNumber = str_pad($invoiceNumber, 5, '0', STR_PAD_LEFT);

        $credit = 0;
        $commission = 0;
        $lg = $request->lead_generator_id ? User::find($request->lead_generator_id) : null;

        // if($client)
        if ($request->lead_generator_id) {
            $lg = User::find($request->lead_generator_id);
        } elseif ('auditor' === $request->paid_to) {
            $lg = $user;
        } else {
            $lg = null;
        }

        if ('not-paid' !== $request->paid_to) {
            $auditorShare = (int) $auditor->payment_share->auditor ?? 50;
            $companyShare = (int) $auditor->payment_share->company ?? 50;
            $auditorCommission = (int) $auditor->payment_share->commission ?? 0;
            $lgCommission = (int) ($lg ? ($lg->payment_share->commission ?? 0) : 0);
            $lgCommission3rdParty = (int) ($lg ? ($lg->payment_share->commission_thirdparty ?? 0) : 0);
            $commissionPayer = $auditor->payment_share->commission_payer ?: 'auditor';

            if ('company' === $request->lead_type) {
                if ('company' === $request->paid_to || 'contractor' === $request->paid_to) {
                    $creditPercent = $auditorShare;
                } elseif ('auditor' === $request->paid_to) {
                    $creditPercent = $companyShare * -1;
                }

                $commissionPercent = 0;
            } elseif ('auditor' === $request->lead_type) {
                if ('company' === $request->paid_to || 'contractor' === $request->paid_to) {
                    $creditPercent = $auditorShare + $auditorCommission;
                } elseif ('auditor' === $request->paid_to) {
                    $creditPercent = ($companyShare - $auditorCommission) * -1;
                }

                $commissionPercent = 0;
            } elseif ('lg' === $request->lead_type) {
                if ('company' === $request->paid_to) {
                    if ('auditor' === $commissionPayer) {
                        $creditPercent = $auditorShare - $lgCommission;
                    } elseif ('company' === $commissionPayer) {
                        $creditPercent = $auditorShare;
                    }
                } elseif ('auditor' === $request->paid_to) {
                    if ('auditor' === $commissionPayer) {
                        $creditPercent = ($companyShare + $lgCommission) * -1;
                    } elseif ('company' === $commissionPayer) {
                        $creditPercent = $companyShare * -1;
                    }
                } elseif ('contractor' === $request->paid_to) {
                    if ('auditor' === $commissionPayer) {
                        $creditPercent = $auditorShare - $lgCommission;
                    } elseif ('company' === $commissionPayer) {
                        $creditPercent = $auditorShare;
                    }
                }
                if ('D' === $request->mode) {
                    $commissionPercent = $request->lead_generator_id === $request->auditor_id ? $lgCommission : $lgCommission3rdParty;
                } else {
                    $commissionPercent = $request->lead_generator_id === $request->auditor_id ? $lgCommission : 0;
                }
            }

            $credit = ($total - $request->costs['gas']) * ($creditPercent / 100);
            $commission = ($total - $request->costs['gas']) * ($commissionPercent / 100);
        }

        $number = str_pad($number, 5, '0', STR_PAD_LEFT);

        $audit = Audit::create([
            'auditor_id' => $auditorId,
            'company_id' => $companyId,
            'creator_id' => $creatorId,
            'lead_generator_id' => $request->lead_generator_id,
            'client_id' => $request->client_id,
            'mode' => $request->mode,
            'lead_type' => $request->lead_type,
            'program' => $request->program,
            'status' => $request->status,
            'number' => $number,
            'deposit' => '0',
            'invoice_number' => $invoiceNumber,
            'date' => $request->date,
            'booking_from' => $request->booking_from,
            'booking_to' => $request->booking_to,
            'costs' => $request->costs,
            'extra_costs' => $request->extra_costs,
            'total_cost' => $total,
            'payment_methods' => $request->payment_methods,
            'paid_by' => $request->paid_by,
            'paid_to' => $request->paid_to,
            'contractor_name' => $request->contractor_name,
            'received_by_company' => false,
            'manual_payment_data' => $request->manual_payment_data,
            'report_sent' => $request->report_sent,
            'report_sent_at' => $request->report_sent_at,
            'credit' => $credit,
            'commission' => $commission,
            'notes' => $request->notes,
            'pair' => $pair,
            'source' => 'new-audit',
        ]);

        if ($request->tax_exempt) {
            $audit->taxExempt()->create(['user_id' => $request->user()->id]);
        }

        if ($request->hasFile('attachments')) {
            $audit->addMultipleMediaFromRequest(['attachments'])->each(function($file): void {
                $uuid = \Illuminate\Support\Str::uuid()->toString();
                $file->toMediaCollection('attachments')->usingFileName($uuid);
            });
        }

        $client = $audit->client;
        $statusChange = $client->calculateStatus();
        $logParams = $audit->client->statusChangeParams($statusChange);

        $auditMode = "{$audit->mode}-Audit";

        if (1 < $audit->pair) {
            $auditMode .= " #{$audit->pair}";
        }

        $client->log('Audit Assignment', sprintf('Assigned %s (%s) to "%s"', $auditMode, $audit->audit_number, $audit->auditor->fullname), $logParams);

        AuditCreated::dispatch($audit);

        $flash = [
            'flash.type' => 'success',
            'flash.message' => 'Audit has been created successfully',
        ];

        if ($user->cannot('update', $audit)) {
            return redirect()->route('audits.index')->with($flash);
        }

        return redirect()->route('audits.update', $audit)->with($flash);
    }

    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Audit $audit) {
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function edit(Audit $audit) {
        $this->authorize('update', $audit);
        $user = auth()->user();
        $audit->loadMissing('leadGenerator', 'client.company', 'client.request', 'auditor', 'company', 'media', 'auditor', 'taxExempt');
        $audit->loadMedia('attachments');

        return inertia('Audits/Edit', ['audit' => $audit, 'pair' => $audit->getPair()]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateAuditRequest $request, Audit $audit) {
        $this->authorize('update', $audit);
        $audit->loadMissing('auditor', 'leadGenerator');
        $auditor = $audit->auditor;
        $lg = $audit->leadGenerator;
        $user = $request->user();

        if ($audit->invoice()->exists()) {
            $audit->fill($request->only(['date', 'booking_from', 'booking_to', 'payment_methods', 'report_sent_at', 'report_sent', 'notes']));
            $audit->save();
        } elseif ('closed' === $audit->status && !$user->is_superuser) {
            $audit->fill($request->only(['date', 'booking_from', 'booking_to', 'payment_methods', 'report_sent_at', 'report_sent', 'notes']));
            $audit->save();
        } else {
            $audit->fill($request->except(['client_id', 'mode', 'company_id', 'auditor_id']));

            if (true === $request->tax_exempt && !$audit->taxExempt()->exists()) {
                $audit->taxExempt()->create(['user_id' => $request->user()->id]);
            } elseif (true !== $request->tax_exempt && $audit->taxExempt()->exists()) {
                $audit->taxExempt()->delete();
            }

            $total = 0;
            $total += $request->costs['utility'];
            $total += $request->costs['cghg'];
            $total += $request->costs['her'];
            $total += $request->costs['gas'];
            $total += $request->costs['drone'];
            $total += $request->costs['infrared'];

            $audit->payment_methods = $request->payment_methods;

            if (is_array($request->extra_costs)) {
                foreach ($request->extra_costs as $cost) {
                    $total += $cost['qty'] * $cost['price'];
                }
            }

            $credit = 0;
            $commission = 0;

            if (0 < $total && 'assigned' === $request->status) {
                return back()->with([
                    'toast' => [
                        'type' => 'error',
                        'message' => 'Audit status cannot be "Assigned" when audit costs are defined',
                    ],
                ]);
            }

            if ('not-paid' !== $request->paid_to) {
                $auditorShare = (int) $auditor->payment_share->auditor;
                $companyShare = (int) $auditor->payment_share->company;
                $auditorCommission = (int) $auditor->payment_share->commission;
                $lgCommission = (int) ($lg ? ($lg->payment_share->commission ?? 0) : 0);
                $lgCommission3rdParty = (int) ($lg ? ($lg->payment_share->commission_thirdparty ?? 0) : 0);
                $commissionPayer = $auditor->payment_share->commission_payer ?: 'auditor';

                if ('company' === $request->lead_type) {
                    if ('company' === $request->paid_to || 'contractor' === $request->paid_to) {
                        $creditPercent = $auditorShare;
                    } elseif ('auditor' === $request->paid_to) {
                        $creditPercent = $companyShare * -1;
                    }

                    $commissionPercent = 0;
                } elseif ('auditor' === $request->lead_type) {
                    if ('company' === $request->paid_to || 'contractor' === $request->paid_to) {
                        $creditPercent = $auditorShare + $auditorCommission;
                    } elseif ('auditor' === $request->paid_to) {
                        $creditPercent = ($companyShare - $auditorCommission) * -1;
                    }

                    $commissionPercent = 0;
                } elseif ('lg' === $request->lead_type) {
                    if ('company' === $request->paid_to) {
                        if ('auditor' === $commissionPayer) {
                            $creditPercent = $auditorShare - $lgCommission;
                        } elseif ('company' === $commissionPayer) {
                            $creditPercent = $auditorShare;
                        }
                    } elseif ('auditor' === $request->paid_to) {
                        if ('auditor' === $commissionPayer) {
                            $creditPercent = ($companyShare + $lgCommission) * -1;
                        } elseif ('company' === $commissionPayer) {
                            $creditPercent = $companyShare * -1;
                        }
                    } elseif ('contractor' === $request->paid_to) {
                        if ('auditor' === $commissionPayer) {
                            $creditPercent = $auditorShare - $lgCommission;
                        } elseif ('company' === $commissionPayer) {
                            $creditPercent = $auditorShare;
                        }
                    }
                    if ('D' === $audit->mode) {
                        $commissionPercent = $request->lead_generator_id === $request->auditor_id ? $lgCommission : $lgCommission3rdParty;
                    } else {
                        $commissionPercent = $request->lead_generator_id === $request->auditor_id ? $lgCommission : 0;
                    }
                }

                $credit = ($total - $request->costs['gas']) * ($creditPercent / 100);
                $commission = ($total - $request->costs['gas']) * ($commissionPercent / 100);

                $audit->fill([
                    'credit' => $credit,
                    'commission' => $commission,
                ]);
            }

            $audit->fill(['total_cost' => $total]);

            if ($audit->isDirty('number')) {
                $oldNumber = $audit->auditor->prefix_code.$audit->mode.$audit->getOriginal('number');
                $oldPath = sprintf('drive/%s/audits/%s', $audit->company_id, $oldNumber);
                $newPath = sprintf('drive/%s/audits/%s', $audit->company_id, $audit->audit_number);

                if (file_exists($oldPath)) {
                    rename(public_path($oldPath), public_path($newPath));
                }

                /* if ($audit->hasPair()) {
                    $pair = $audit->getPair();
                    $pairOldNumber = $pair->auditor->prefix_code.$pair->mode.$pair->getOriginal('number');
                    $pairOldPath = sprintf('drive/%s/audits/%s', $pair->company_id, $pairOldNumber);
                    $pairNewPath = sprintf('drive/%s/audits/%s', $pair->company_id, $pair->audit_number);

                    if (file_exists($pairOldPath)) {
                        rename(public_path($pairOldPath), public_path($pairNewPath));
                    }

                    $pair->number = $audit->number;
                    $pair->save();
                } */
            }

            $audit->save();
        }

        if ($request->hasFile('attachments')) {
            collect($audit->addMultipleMediaFromRequest(['attachments']))->each(function($file): void {
                $file->toMediaCollection('attachments');
            });
        }

        $client = $audit->client;
        $statusChange = $client->calculateStatus();
        $logParams = $audit->client->statusChangeParams($statusChange);

        $auditMode = "{$audit->mode}-Audit";

        if (1 < $audit->pair) {
            $auditMode .= " #{$audit->pair}";
        }

        $client->log('Audit Modification', sprintf('Updated client\'s %s (%s)', $auditMode, $audit->audit_number), $logParams);

        AuditUpdated::dispatch($audit);

        return back()->with([
            'flash.type' => 'success',
            'flash.message' => 'Audit edited successfully',
        ]);
    }

    public function updateReceive(Request $request, Audit $audit) {
        $this->authorize('update', $audit);

        $request->validate(['received' => ['required', 'boolean']]);

        $manual = (object) $audit->manual_payment_data;
        $manual->received_by_company = null;

        $audit->manual_payment_data = $manual;
        $audit->received_by_company = $request->received;
        $audit->receiver()->associate(auth()->user());

        $audit->save();

        return back()->with([
            'toast' => [
                'type' => 'success',
                'message' => 'Audit updated successfully',
            ],
        ]);
    }

    /**
     * Remove a specified media resource's media collection.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeFile(Media $media) {
        $this->authorize('audits.update');

        $media->delete();

        return back()->with([
            'flash.type' => 'success',
            'flash.message' => 'File has ben deleted successfully.',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, Audit $audit) {
        $this->authorize('remove', $audit);
        $user = $request->user();
        $companyId = $audit->company_id;

        if (!$user->is_superuser && $audit->creator_id !== $user->id) {
            $request->validate([
                'reason' => 'required|string|in:send-back,client-refuse,e-done-other-auditor,d-done-other-auditor,done-other-auditor,auditor-refuse,wrong-assignment',
            ]);
        }

        $client = $audit->client;
        $number = $audit->audit_number;
        $creator = $audit->creator;
        $audit->deleteOrFail();

        if ($client) {
            if (!$user->is_superuser && $audit->creator_id !== $user->id) {
                $reason = match ($request->reason) {
                    'send-back' => 'Send it back to office to be booked.',
                    'client-refuse' => 'Client no longer wants the audit',
                    'e-done-other-auditor' => 'The E-Audit has already been done by other auditor.',
                    'd-done-other-auditor' => 'The D-Audit has already been done by other auditor.',
                    'done-other-auditor' => 'D & E Audit has been done by other auditor.',
                    'auditor-refuse' => "I don't want to do this audit.",
                    'wrong-assignment' => 'Wrong assignment,',
                    default => '',
                };

                $statusId = match ($request->reason) {
                    'send-back' => 26,
                    'client-refuse' => 21,
                    'e-done-other-auditor' => 26,
                    'd-done-other-auditor' => 26,
                    'done-other-auditor' => 23,
                    'auditor-refuse' => 26,
                    'wrong-assignment' => 26,
                    default => '',
                };

                $message = "Audit \"{$number}\" has been removed by auditor <br>Reason: <br> \"<i>{$reason}</i>\"";

                $client->comments()->create([
                    'user_id' => $user->id,
                    'content' => $message,
                ]);

                $logParams = [];

                if ($client->client_status_id !== $statusId) {
                    $prevStatus = $client->status->title;
                    $client->status()->associate($statusId)->save();
                    $logParams = [
                        'prevStatus' => $prevStatus,
                        'status' => $client->status->title,
                    ];
                }

                $client->log('Audit Removal', sprintf('Removed an audit with number "%s"', $number), $logParams);
            } else {
                $statusChange = $client->calculateStatus();
                $logParams = [];

                if (false !== $statusChange) {
                    [$prevStatus, $nextStatus] = $statusChange;

                    $logParams = [
                        'prevStatus' => $prevStatus,
                        'status' => $nextStatus,
                    ];
                }

                $client->log('Audit Removal', sprintf('Removed an audit with number "%s"', $number), $logParams);
            }
        }

        if (!$user->is_superuser && $audit->creator_id !== $user->id) {
            $reason = match ($request->reason) {
                'send-back' => 'Send it back to office to be booked.',
                'client-refuse' => 'Client no longer wants the audit',
                'e-done-other-auditor' => 'The E-Audit has already been done by other auditor.',
                'd-done-other-auditor' => 'The D-Audit has already been done by other auditor.',
                'done-other-auditor' => 'D & E Audit has been done by other auditor.',
                'auditor-refuse' => "I don't want to do this audit.",
                'wrong-assignment' => 'Wrong assignment,',
            };

            AuditorRefused::dispatch($creator, $number, $request->user(), $reason);
        }

        return back()->with([
            'flash.type' => 'success',
            'flash.message' => 'Audit removed successfully',
        ]);
    }

    /**
     * Detach the specified attachment form resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeAttachment(Audit $audit, Media $media) {
        $this->authorize('update', $audit);

        $media->deleteOrFail();

        return back()->with([
            'flash.type' => 'success',
            'flash.message' => 'Attachment has been removed successfully',
        ]);
    }

    public function exportAsPdf(Request $request, Audit $audit) {
        $audit->loadMissing('auditor', 'company', 'client');
        $pair = $audit->getPair();

        if (true === $request->pair && !$pair) {
            return redirect()->route('audits.index')->with([
                'flash' => [
                    'type' => 'danger',
                    'message' => 'The selected has no pair to export both',
                ],
            ]);
        }

        $pdf = new Html2Pdf('P', 'A4', 'en');
        $roboto = \TCPDF_FONTS::addTTFfont(resource_path('fonts/pdf/Roboto.ttf'), 'TrueType', '', 32);
        $robotoBold = \TCPDF_FONTS::addTTFfont(resource_path('fonts/pdf/Roboto-Bold.ttf'), 'TrueType', '', 32);
        $playfair = \TCPDF_FONTS::addTTFfont(resource_path('fonts/pdf/Playfair.ttf'), 'TrueType', '', 32);
        $playfairBold = \TCPDF_FONTS::addTTFfont(resource_path('fonts/pdf/Playfair-Bold.ttf'), 'TrueType', '', 32);

        $meta = $audit->company->meta;
        $invPrefix = (1 === (int) $request->pair) ? ($meta?->paired_audit_client_invoice_prefix ?? 'FSIN') : ($meta?->single_audit_client_invoice_prefix ?? 'SIN');
        $eTransferEmail = $meta->eTransfer_email ?? $audit->company->email;

        $footerTextVars = [
            '{companyName}' => $audit->company->name,
            '{companyMail}' => $audit->company->email,
            '{companyETransferMail}' => $eTransferEmail ?? $audit->company->email,
            '{companyCellphone}' => $audit->company->cellphone,
            '{companyFax}' => $audit->company->fax,
            '{companyHST}' => $audit->company->hst,
            '{invoiceNumber}' => $invPrefix.$audit->invoice_number,
            '{auditNumber}' => $audit->audit_number,
            '{auditorName}' => $audit->auditor->fullname,
            '{auditorMail}' => $audit->auditor->email,
            '{auditorMobile}' => $audit->auditor->masked_mobile,
            '{auditorPhone}' => $audit->auditor->masked_phone,
            '{auditorOrganization}' => $audit->auditor->organization?->name ?: $audit->auditor->fullname,
            '{auditorOrganizationHST}' => $audit->auditor->organization?->hst ?: '',
        ];

        $footerText = $meta->pdf_footer_text ?? null;
        $footerText = str_ireplace(array_keys($footerTextVars), array_values($footerTextVars), $footerText);
        $footerText = str_ireplace(['<p', '</p'], ['<div', '</div'], $footerText);

        $logo = null;
        if ('auditor' === $audit->paid_to) {
            if (isset($audit->auditor->organization->logo) && !empty($audit->auditor->organization?->logo)) {
                $logo = $audit->auditor->organization?->logo;
            }
        } else {
            if (!empty($audit->company->logo)) {
                $logo = $audit->company->logo;
            }
        }

        $data = [
            'audit' => $audit,
            'pair' => false,
            'eTransferEmail' => $eTransferEmail,
            'companyNameColor' => $meta->pdf_company_name_color ?? '#ee3333',
            'footerTextColor' => $meta->pdf_footer_text_color ?? '#ee3333',
            'footerText' => $footerText ?? '',
            'invoicePrefix' => $invPrefix,
            'logo' => $logo,
            'stamp' => false,
            'fonts' => (object) [
                'roboto' => [
                    'regular' => $roboto,
                    'bold' => $robotoBold,
                ],

                'playfair' => [
                    'regular' => $playfair,
                    'bold' => $playfairBold,
                ],

                'body' => $roboto,
                'title' => $playfairBold,
            ],
        ];

        if ('1' == $request->stamp) {
            if ($audit->is_received || 'auditor' === $audit->paid_to) {
                $data['stamp'] = true;
            } else {
                return redirect()->route('audits.index')->with([
                    'flash' => [
                        'type' => 'danger',
                        'message' => 'This audit is not received by company or paid to auditor and paid stamp cannot be included',
                    ],
                ]);
            }
        }

        $view = 'PDF.audits.single';
        if ($request->pair) {
            $data['audits'] = [
                $audit->mode => $audit,
                'D' === $audit->mode ? 'E' : 'D' => $pair,
            ];

            $data['pair'] = true;

            if ('1' == $request->stamp) {
                if ($pair->is_received || 'auditor' === $pair->paid_to) {
                    $data['stamp'] = true;
                } else {
                    return redirect()->route('audits.index')->with([
                        'flash' => [
                            'type' => 'danger',
                            'message' => 'This audit is not received by company or paid to auditor and paid stamp cannot be included',
                        ],
                    ]);
                }
            }

            $view = 'PDF.audits.paired';
        }

        $view = view($view, $data)->render();
        $filename = "{$invPrefix}{$audit->invoice_number}.pdf";
        $pdf->pdf->SetDisplayMode('real');
        $pdf->WriteHTML($view);

        return $pdf->output($filename, $request->has('preview') ? 'I' : 'D');
    }

    public function emailPDF(Request $request, Audit $audit) {
        $audit->loadMissing('auditor', 'company', 'client');
        $pair = $audit->getPair();

        if (true === $request->pair && !$pair) {
            return redirect()->route('audits.index')->with([
                'flash' => [
                    'type' => 'danger',
                    'message' => 'The selected has no pair to export both',
                ],
            ]);
        }

        $pdf = new Html2Pdf('P', 'A4', 'en');
        $roboto = \TCPDF_FONTS::addTTFfont(resource_path('fonts/pdf/Roboto.ttf'), 'TrueType', '', 32);
        $robotoBold = \TCPDF_FONTS::addTTFfont(resource_path('fonts/pdf/Roboto-Bold.ttf'), 'TrueType', '', 32);
        $playfair = \TCPDF_FONTS::addTTFfont(resource_path('fonts/pdf/Playfair.ttf'), 'TrueType', '', 32);
        $playfairBold = \TCPDF_FONTS::addTTFfont(resource_path('fonts/pdf/Playfair-Bold.ttf'), 'TrueType', '', 32);

        $meta = $audit->company->meta;
        $invPrefix = (1 === (int) $request->pair) ? ($meta?->paired_audit_client_invoice_prefix ?? 'FSIN') : ($meta?->single_audit_client_invoice_prefix ?? 'SIN');
        $eTransferEmail = $meta->eTransfer_email ?? $audit->company->email;

        $footerTextVars = [
            '{companyName}' => $audit->company->name,
            '{companyMail}' => $audit->company->email,
            '{companyETransferMail}' => $eTransferEmail ?? $audit->company->email,
            '{companyCellphone}' => $audit->company->cellphone,
            '{companyFax}' => $audit->company->fax,
            '{companyHST}' => $audit->company->hst,
            '{invoiceNumber}' => $invPrefix.$audit->invoice_number,
            '{auditNumber}' => $audit->audit_number,
            '{auditorName}' => $audit->auditor->fullname,
            '{auditorMail}' => $audit->auditor->email,
            '{auditorMobile}' => $audit->auditor->masked_mobile,
            '{auditorPhone}' => $audit->auditor->masked_phone,
            '{auditorOrganization}' => $audit->auditor->organization?->name ?: $audit->auditor->fullname,
            '{auditorOrganizationHST}' => $audit->auditor->organization?->hst ?: '',
        ];

        $footerText = $meta->pdf_footer_text ?? null;
        $footerText = str_ireplace(array_keys($footerTextVars), array_values($footerTextVars), $footerText);
        $footerText = str_ireplace(['<p', '</p'], ['<div', '</div'], $footerText);

        $logo = null;
        if ('auditor' === $audit->paid_to) {
            if (isset($audit->auditor->organization->logo) && !empty($audit->auditor->organization?->logo)) {
                $logo = $audit->auditor->organization?->logo;
            }
        } else {
            if (!empty($audit->company->logo)) {
                $logo = $audit->company->logo;
            }
        }

        $data = [
            'audit' => $audit,
            'pair' => false,
            'eTransferEmail' => $eTransferEmail,
            'companyNameColor' => $meta->pdf_company_name_color ?? '#ee3333',
            'footerTextColor' => $meta->pdf_footer_text_color ?? '#ee3333',
            'footerText' => $footerText ?? '',
            'invoicePrefix' => $invPrefix,
            'logo' => $logo,
            'stamp' => false,
            'fonts' => (object) [
                'roboto' => [
                    'regular' => $roboto,
                    'bold' => $robotoBold,
                ],

                'playfair' => [
                    'regular' => $playfair,
                    'bold' => $playfairBold,
                ],

                'body' => $roboto,
                'title' => $playfairBold,
            ],
        ];

        if ('1' == $request->stamp) {
            if ($audit->is_received || 'auditor' === $audit->paid_to) {
                $data['stamp'] = true;
            } else {
                return redirect()->route('audits.index')->with([
                    'flash' => [
                        'type' => 'danger',
                        'message' => 'This audit is not received by company or paid to auditor and paid stamp cannot be included',
                    ],
                ]);
            }
        }

        $view = 'PDF.audits.single';
        if ($request->pair) {
            $data['audits'] = [
                $audit->mode => $audit,
                'D' === $audit->mode ? 'E' : 'D' => $pair,
            ];

            $data['pair'] = true;

            if ('1' == $request->stamp) {
                if ($pair->is_received || 'auditor' === $pair->paid_to) {
                    $data['stamp'] = true;
                } else {
                    return redirect()->route('audits.index')->with([
                        'flash' => [
                            'type' => 'danger',
                            'message' => 'This audit is not received by company or paid to auditor and paid stamp cannot be included',
                        ],
                    ]);
                }
            }

            $view = 'PDF.audits.paired';
        }

        $view = view($view, $data)->render();
        $filename = "{$invPrefix}{$audit->invoice_number}.pdf";
        $pdf->pdf->SetDisplayMode('real');
        $pdf->WriteHTML($view);

        $pdfData = $pdf->output($filename, 'S');

        $mail = new ClientInvoicePDF($audit, $pdfData, $filename);

        Mail::to($audit->client->email)->send($mail);

        return back()->with([
            'flash' => [
                'type' => 'success',
                'message' => 'Invoice sent to client\'s email successfully',
            ],
        ]);
    }

    public function assignToAuditor(Request $request, Audit $audit) {
        $this->authorize('assign', $audit);

        $request->validate([
            'auditor_id' => [
                'numeric',
                'required',
            ],
        ]);

        if ('booked' !== $audit->status || null !== $audit->auditor_id) {
            throw ValidationException::withMessages(['auditor_id' => 'Selected audit already has an auditor']);
        }

        if (!User::role('auditor')->whereId($request->auditor_id)->exists()) {
            throw ValidationException::withMessages(['auditor_id' => 'Selected user is not a auditor']);
        }

        $pairedAudit = $audit->client->audits()->wherePair($audit->pair)->first();

        if ($pairedAudit && $pairedAudit->auditor_id === $request->auditor_id) {
            if ($pairedAudit->auditor_id === $request->auditor_id) {
                $number = $pairedAudit->number;
            } else {
                $lastAudit = Audit::whereAuditorId($request->auditor_id)->where('number', '>', '90000')->latest('number')->first();

                if ($lastAudit) {
                    $number = $lastAudit->number + 1;
                } else {
                    $number = 90001;
                }
            }
        } else {
            if ('D' === $audit->mode) {
                $lastAudit = Audit::whereAuditorId($request->auditor_id)->where('number', '!=', '')->where('number', '<', '90000')->latest('number')->first();
                $number = (isset($lastAudit->number) ? (int) ($lastAudit->number) : 0) + 1;
            } else {
                $lastAudit = Audit::whereAuditorId($request->auditor_id)->where('number', '!=', '')->where('number', '>', '90000')->latest('number')->first();
                if (!$lastAudit) {
                    $number = 90001;
                } else {
                    $number = (isset($lastAudit->number) ? (int) ($lastAudit->number) : 0) + 1;
                }
            }
        }

        $invoiceNumber = 1;
        $latestInvoice = Audit::whereCompanyId($audit->company_id)->latest('invoice_number')->first();

        if ($latestInvoice) {
            $invoiceNumber = (int) $latestInvoice->invoice_number ?? 1;
            ++$invoiceNumber;
        }

        $invoiceNumber = str_pad($invoiceNumber, 5, '0', STR_PAD_LEFT);

        $audit->invoice_number = $invoiceNumber;

        $audit->auditor_id = $request->auditor_id;
        $audit->status = 'assigned';
        $audit->number = str_pad($number, 5, '0', STR_PAD_LEFT);

        $audit->save();

        $companyAuditorAssignmentMail = '0' !== ($audit->company->meta->audit_assignment_auditor_email ?? true);
        $companyClientAssignmentMail = '0' !== ($audit->company->meta->audit_assignment_client_email ?? true);

        $emails = [
            'auditor' => $companyAuditorAssignmentMail && $request->input('emails.auditor'),
            'client' => $companyClientAssignmentMail && $request->input('emails.client'),
        ];

        $statusChange = $audit->client->calculateStatus();
        $logParams = [
            'notified' => $emails,
        ];

        if (false !== $statusChange) {
            [$prev, $status] = $statusChange;

            $logParams['prevStatus'] = $prev;
            $logParams['status'] = $status;
        }

        $audit->client->log('Audit Assignment', sprintf('Assigned %s-audit to %s', $audit->mode, $audit->auditor->fullname), $logParams);

        AuditAssigned::dispatch(
            $audit,
            $emails['auditor'],
            $emails['client'],
        );

        return back()->with([
            'flash' => [
                'type' => 'success',
                'message' => 'Audit assigned to auditor successfully',
            ],
        ]);
    }

    public function exportCSV(Request $request) {
        $this->authorize('audits.export');

        $user = $request->user();
        $audits = [];

        if ($user->is_admin) {
            $audits = Audit::query();
        } elseif ($user->is_superuser) {
            $audits = $user->company->audits();
        } else {
            $audits = $user->audits();
        }

        $audits = $audits->with(['leadGenerator', 'auditor', 'client', 'invoice']);

        $audits->when($request->filled('filters'), function($q) use ($request): void {
            if ($request->filled('filters.status')) {
                $q->whereStatus($request->filters['status']);
            }
            if ($request->filled('filters.company')) {
                $q->whereCompanyId($request->filters['company']);
            }
            if ($request->filled('filters.lead_generator_id')) {
                $q->whereLeadGeneratorId($request->filters['lead_generator_id']);
            }
            if ($request->filled('filters.auditor_id')) {
                $q->whereAuditorId($request->filters['auditor_id']);
            }
            if ($request->filled('filters.mode')) {
                $q->whereMode($request->filters['mode']);
            }
            if ($request->filled('filters.source')) {
                $q->whereSource($request->filters['source']);
            }
            if ($request->filled('filters.from_date')) {
                $q->whereDate('created_at', '>=', $request->filters['from_date']);
            }
            if ($request->filled('filters.to_date')) {
                $q->whereDate('created_at', '<=', $request->filters['to_date']);
            }
            if ($request->filled('filters.invoice_issued')) {
                $q->where(
                    function($q) use ($request): void {
                        if ('true' === $request->filters['invoice_issued']) {
                            $q
                                ->where('manual_payment_data->invoice_issued', true)
                                ->orWhereHas('invoice')
                            ;
                        } else {
                            $q
                                ->where(function($x): void {
                                    $x->where('manual_payment_data->invoice_issued', false)->orWhereNull('manual_payment_data->invoice_issued');
                                })
                                ->doesntHave('invoice')
                            ;
                        }
                    }
                );
            }
        });

        $audits = $audits->where('status', '!=', 'booked')->latest()->get();
        $fileName = sprintf('Audits-%s.csv', now()->format('Y-m-d-H-i-s'));
        $appNumPrefix = optional($user->company->meta)->application_number_prefix ?? 'AP';
        $invPrefix = optional($user->company->meta)->auditor_invoice_prefix ?? 'INV';
        $clientInvPrefix = optional($user->company->meta)->single_audit_client_invoice_prefix ?? 'SIN';
        $clientInvPrefixPaired = optional($user->company->meta)->paired_audit_client_invoice_prefix ?? 'SIN';

        return response()->streamDownload(function() use ($audits, $appNumPrefix, $invPrefix, $clientInvPrefix, $clientInvPrefixPaired): void {
            set_time_limit(0);
            $columns = [
                'Number',
                'Mode',
                'Prefix Code',
                'Auditor',
                'Lead Generator',
                'Client',
                'Email',
                'Mobile',
                'Phone',
                'Address',
                'Lead Type',
                'Utility',
                'CGHG',
                'HER+',
                'Date',
                'Time',
                'Audit Cost',
                'Extra Cost',
                'Total Cost',
                'TAX-Exempt',
                'Credit',
                'Status',
                'Application Number',
                'Client Invoice Number',
                'Paid To',
                'Paid By',
                'Received',
                'Auditor Invoice Number',
                'Invoice Paid',
                'Notes',
            ];

            ini_set('auto_detect_line_endings', 1);

            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($audits as $audit) {
                $costs = 0;
                $costs += (float) ($audit->costs->utility ?? 0);
                $costs += (float) ($audit->costs->cghg ?? 0);
                $costs += (float) ($audit->costs->her ?? 0);
                $costs += (float) ($audit->costs->gas ?? 0);
                $costs += (float) ($audit->costs->drone ?? 0);
                $costs += (float) ($audit->costs->infrared ?? 0);

                $extraCosts = collect($audit->extra_costs)->sum(fn ($cost) => (float) $cost->item * (float) $cost->qty);

                fputcsv($file, [
                    $audit->audit_number,
                    strtoupper($audit->mode),
                    $audit->auditor->prefix_code,
                    $audit->auditor->fullname ?? '',
                    $audit->lead_generator->fullname ?? '',
                    $audit->client->fullname ?? '',
                    $audit->client->email ?? '',
                    $audit->client->masked_mobile ?? '',
                    $audit->client->masked_phone ?? '',
                    $audit->client->formatted_address ?? '',
                    ucwords($audit->lead_type),
                    true === ($audit->program->utility ?? false) ? 'Yes' : 'No',
                    true === ($audit->program->cghg ?? false) ? 'Yes' : 'No',
                    true === ($audit->program->her ?? false) ? 'Yes' : 'No',
                    $audit->date,
                    sprintf('%s-%s', $audit->booking_from ?: 'Not Set', $audit->booking_to ?: 'Not Set'),
                    $costs,
                    (float) $extraCosts,
                    (float) $audit->total_cost,
                    $audit->taxExempt()->exists() ? 'Yes' : 'No',
                    (float) $audit->credit,
                    ucfirst($audit->status),
                    $audit->client->application_number ? sprintf('%s%s', $appNumPrefix, $audit->client->application_number) : '',
                    sprintf('%s%s', $audit->hasPair() ? $clientInvPrefixPaired : $clientInvPrefix, $audit->invoice_number),
                    ucwords(str_replace('-', ' ', $audit->paid_to)) ?? '',
                    ucwords(str_replace('-', ' ', $audit->paid_by)) ?? '',
                    $audit->is_received ? 'Yes' : 'No',
                    !empty($audit->invoice) ? sprintf('%s%s', $invPrefix, $audit->invoice->number) : 'Not Issued',
                    $audit->invoice ? ('paid' === $audit->invoice->status ? 'Yes' : 'No') : '-',
                    preg_replace('/\\s+/usim', ' ', $audit->notes),
                ]);
            }

            fclose($file);
        }, $fileName);
    }

    public function exportExcel(Request $request, string $hash) {
        try {
            $companyId = Crypt::decryptString($hash);
            $company = Company::find($companyId);
            $audits = $company
                ->audits()
                ->with(['leadGenerator', 'auditor', 'client', 'invoice'])
                ->where('status', '!=', 'booked')
                ->latest()
                ->get()
            ;

            $appNumPrefix = optional($company->meta)->application_number_prefix ?? 'AP';
            $invPrefix = optional($company->meta)->auditor_invoice_prefix ?? 'INV';
            $clientInvPrefix = optional($company->meta)->single_audit_client_invoice_prefix ?? 'SIN';
            $clientInvPrefixPaired = optional($company->meta)->paired_audit_client_invoice_prefix ?? 'SIN';

            return view('audits.excel', compact(
                'audits',
                'appNumPrefix',
                'invPrefix',
                'clientInvPrefix',
                'clientInvPrefixPaired',
            ));
        } catch (\Throwable $err) {
            echo 'link is not valid';

            exit;
        }
    }

    public function mapView()
    {
        return inertia('Audits/map-view/index');
    }
}
