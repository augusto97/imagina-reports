import { useEffect, useState } from 'react';

/** «0:42» since `running` turned on, while the AI works. */
export function useElapsed(running: boolean): string {
    const [seconds, setSeconds] = useState(0);
    useEffect(() => {
        if (!running) {
            setSeconds(0);
            return;
        }
        const started = Date.now();
        const timer = setInterval(() => setSeconds(Math.floor((Date.now() - started) / 1000)), 1000);
        return () => clearInterval(timer);
    }, [running]);

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}
