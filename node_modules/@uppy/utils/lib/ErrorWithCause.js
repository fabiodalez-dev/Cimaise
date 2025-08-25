import hasProperty from './hasProperty.js';
class ErrorWithCause extends Error {
    isNetworkError;
    cause;
    constructor(message, options) {
        super(message);
        this.cause = options?.cause;
        if (this.cause && hasProperty(this.cause, 'isNetworkError')) {
            this.isNetworkError = this.cause.isNetworkError;
        }
        else {
            this.isNetworkError = false;
        }
    }
}
export default ErrorWithCause;
