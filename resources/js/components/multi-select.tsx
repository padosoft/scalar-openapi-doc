import { useId, useState } from 'react';
import { Button } from '@/components/ui/button';

type Option = {
    value: string;
    label: string;
};

type MultiSelectProps = {
    label: string;
    options: Option[];
    selected: string[];
    onChange: (value: string[]) => void;
    emptyLabel?: string;
    id?: string;
};

export function MultiSelect({
    label,
    options,
    selected,
    onChange,
    emptyLabel = 'No items selected',
    id,
}: MultiSelectProps) {
    const controlId = id ?? useId();
    const [open, setOpen] = useState(false);

    return (
        <div className="space-y-2">
            <label htmlFor={controlId} className="text-sm font-medium">
                {label}
            </label>
            <Button
                type="button"
                id={controlId}
                variant="outline"
                className="w-full justify-between"
                onClick={() => setOpen((current) => !current)}
            >
                <span className="max-w-full truncate">
                    {selected.length > 0
                        ? `${selected.length} selected`
                        : emptyLabel}
                </span>
                <span aria-hidden>▾</span>
            </Button>

            {open && (
                <div className="rounded-md border bg-background p-2">
                    {options.length === 0 && (
                        <p className="px-1 py-2 text-sm text-muted-foreground">
                            No options available.
                        </p>
                    )}
                    <div className="space-y-2">
                        {options.map((option) => {
                            const isChecked = selected.includes(option.value);

                            return (
                                <label
                                    key={option.value}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        checked={isChecked}
                                        onChange={() =>
                                            onChange(
                                                isChecked
                                                    ? selected.filter(
                                                          (value) =>
                                                              value !== option.value,
                                                      )
                                                    : [...selected, option.value],
                                            )
                                        }
                                    />
                                    <span>{option.label}</span>
                                </label>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

