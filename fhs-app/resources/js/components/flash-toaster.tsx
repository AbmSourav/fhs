import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { Toaster } from 'sonner';

/**
 * Renders server flash messages as toasts.
 *
 * Mount once per layout. Two of them on screen at the same time would show
 * every message twice.
 */
export default function FlashToaster() {
    const { appearance } = useAppearance();

    useFlashToast();

    return <Toaster position="top-right" richColors closeButton theme={appearance} />;
}
