import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';

type ConfirmDialogProps = {
    title: string;
    description: string;
    onOpenChange?: (open: boolean) => void;
    confirmLabel?: string;
    cancelLabel?: string;
    onConfirm: () => void;
    children: ReactNode;
    destructive?: boolean;
};

export function ConfirmDialog({
    title,
    description,
    onOpenChange,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    onConfirm,
    children,
    destructive = true,
}: ConfirmDialogProps) {
    const [open, setOpen] = useState(false);
    const handleOpenChange = (nextOpen: boolean): void => {
        setOpen(nextOpen);
        onOpenChange?.(nextOpen);
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription className="text-muted-foreground text-sm">
                        {description}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            {cancelLabel}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={() => {
                            onConfirm();
                            handleOpenChange(false);
                        }}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
