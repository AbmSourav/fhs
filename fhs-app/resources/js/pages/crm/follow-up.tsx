import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { businessInputToUtc, businessNow, businessToday, formatDateTime, toBusinessInputValue } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { type FollowUp, type OutcomeOption } from '@/types/customer';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, MapPin, Phone } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm' },
    { title: 'Follow-up', href: '#' },
];

interface Props {
    followUp: FollowUp;
    outcomes: OutcomeOption[];
}

export default function CrmFollowUp({ followUp, outcomes }: Props) {
    const { customer } = followUp;

    const { data, setData, post, transform, errors, processing } = useForm({
        outcome: followUp.outcome,
        note: followUp.note,
        // Prefilled from when the call was placed, in business time.
        called_at: toBusinessInputValue(followUp.called_at),
        call_again_on: '',
        // Carried through so submitting returns to the list they came from,
        // with its filter and threshold intact.
        filters: typeof window !== 'undefined' ? window.location.search : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        // The date field works in business time, but timestamps are stored in
        // UTC. transform rewrites only what is sent, leaving the input alone.
        transform((payload) => ({
            ...payload,
            called_at: businessInputToUtc(payload.called_at),
        }));

        post(`/crm/${customer.id}/followup/${followUp.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Call — ${customer.name}`} />

            <div className="px-4 py-6">
                <Button variant="outline" asChild className="mb-8 shrink-0 self-start">
                    <Link href="/crm">
                        <ArrowLeft className="mr-1 size-4" />
                        Back to CRM
                    </Link>
                </Button>

                <div className="grid gap-10 lg:grid-cols-[minmax(0,28rem)_minmax(0,1fr)]">
                    <div>
                        <div className="rounded-lg border p-4">
                            <p className="font-medium">{customer.name}</p>

                            {customer.mobile_number && (
                                <a
                                    href={`tel:${customer.mobile_number}`}
                                    className="text-muted-foreground mt-1 flex items-center gap-1 text-sm hover:underline"
                                >
                                    <Phone className="size-3" />
                                    {customer.mobile_number}
                                </a>
                            )}

                            {customer.address && (
                                <p className="text-muted-foreground mt-1 flex items-start gap-1 text-xs">
                                    <MapPin className="mt-0.5 size-3 shrink-0" />
                                    {customer.address}
                                </p>
                            )}
                        </div>

                        <form onSubmit={submit} className="mt-6 space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="outcome">How did the call go?</Label>
                                <Select value={data.outcome} onValueChange={(value) => setData('outcome', value)}>
                                    <SelectTrigger id="outcome">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {outcomes.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.outcome} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="called_at">When was the call?</Label>
                                <Input
                                    id="called_at"
                                    className="block"
                                    type="datetime-local"
                                    value={data.called_at}
                                    onChange={(e) => setData('called_at', e.target.value)}
                                    // Stops the picker offering a future
                                    // moment. The server rejects one regardless.
                                    max={businessNow()}
                                    required
                                />
                                <p className="text-muted-foreground text-xs">
                                    Filled in when you placed the call. Change it if you are writing up an earlier one.
                                </p>
                                <InputError message={errors.called_at} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="note">
                                    Note <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <textarea
                                    id="note"
                                    rows={4}
                                    value={data.note}
                                    onChange={(e) => setData('note', e.target.value)}
                                    placeholder="Wants delivery after Eid"
                                    className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden"
                                />
                                <InputError message={errors.note} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="call_again_on">
                                    Call again on <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <Input
                                    id="call_again_on"
                                    className="block"
                                    type="date"
                                    value={data.call_again_on}
                                    onChange={(e) => setData('call_again_on', e.target.value)}
                                    // A promised callback is always ahead, so
                                    // the picker offers nothing behind today.
                                    min={businessToday()}
                                />
                                <p className="text-muted-foreground text-xs">Set this if they asked to be called back.</p>
                                <InputError message={errors.call_again_on} />
                            </div>

                            <div className="flex items-center gap-3">
                                <Button disabled={processing}>Save call</Button>

                                <Button variant="secondary" className="px-6" asChild>
                                    <Link href="/crm">Cancel</Link>
                                </Button>
                            </div>
                        </form>
                    </div>

                    {/* What was said last time, so this call can pick up where
                        the previous one left off. */}
                    {followUp.history.length > 0 && (
                        <div>
                            <h2 className="font-medium">Earlier calls</h2>

                            <ul className="mt-3 space-y-2 text-sm">
                                {followUp.history.map((past) => (
                                    <li key={past.id} className="rounded-md border p-3">
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="font-medium">{past.outcome_label}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {formatDateTime.format(new Date(past.called_at))}
                                            </span>
                                        </div>

                                        {past.note && <p className="text-muted-foreground mt-1 text-xs">{past.note}</p>}

                                        {past.called_by && <p className="text-muted-foreground mt-1 text-xs">by {past.called_by}</p>}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
