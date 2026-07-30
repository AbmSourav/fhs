import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

/**
 * Surfaces server flash messages as toasts.
 *
 * Any controller can trigger one with `->with('success', '...')` or
 * `->with('error', '...')`; nothing else is needed at the call site.
 *
 * Mounted once in the app layout — calling it per page would fire duplicate
 * toasts for the same message.
 */
export function useFlashToast() {
    const { flash } = usePage<SharedData>().props;

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }

        if (flash?.error) {
            toast.error(flash.error);
        }

    }, [flash?.success, flash?.error]);
}
