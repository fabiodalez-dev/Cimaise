import getBytesRemaining from './getBytesRemaining.js';
import getSpeed from './getSpeed.js';
export default function getETA(fileProgress) {
    if (!fileProgress.bytesUploaded)
        return 0;
    const uploadSpeed = getSpeed(fileProgress);
    const bytesRemaining = getBytesRemaining(fileProgress);
    const secondsRemaining = Math.round((bytesRemaining / uploadSpeed) * 10) / 10;
    return secondsRemaining;
}
