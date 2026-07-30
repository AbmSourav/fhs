import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

/**
 * Adds a brand from within the catalogue setup page, so a missing brand does not
 * force the user to leave the form they are filling in.
 *
 * Posts to brands.store and relies on the redirect-back to refresh the parent
 * page's brand options.
 */
export default function AddBrandSheet() {
    const [open, setOpen] = useState(false);

    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({
        name: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/brands', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    // Discard half-typed input and stale errors when the sheet is dismissed,
    // so reopening it starts clean.
    const handleOpenChange = (next: boolean) => {
        setOpen(next);

        if (!next) {
            reset();
            clearErrors();
        }
    };

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            <SheetTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <Plus className="mr-1 size-4" />
                    Add brand
                </Button>
            </SheetTrigger>

            <SheetContent className="w-full max-w-none sm:max-w-lg lg:max-w-xl">
                <form onSubmit={submit} className="flex h-full flex-col">
                    <SheetHeader className="mb-6 text-left">
                        <SheetTitle>Add brand</SheetTitle>
                        <SheetDescription>
                            Brands are shared across the catalogue — add one once and reuse it for every weight you stock.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex-1 space-y-6 py-6">
                        <div className="grid gap-2">
                            <Label htmlFor="brand-name">Brand name</Label>

                            <Input
                                id="brand-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Jamuna"
                                autoFocus
                                required
                            />

                            <InputError message={errors.name} />
                        </div>
                    </div>

                    <SheetFooter className="flex-row justify-start gap-2 sm:justify-start">
                        <Button className="flex-1 px-8 sm:flex-none" type="submit" disabled={processing || data.name.trim() === ''}>
                            {processing ? 'Adding…' : 'Add brand'}
                        </Button>

                        <Button className="flex-1 px-8 sm:flex-none" type="button" variant="outline" onClick={() => handleOpenChange(false)}>
                            Cancel
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
