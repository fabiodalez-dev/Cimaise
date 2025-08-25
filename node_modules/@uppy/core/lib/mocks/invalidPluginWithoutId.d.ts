import UIPlugin from '../UIPlugin.js';
import type Uppy from '../Uppy.js';
export default class InvalidPluginWithoutName extends UIPlugin<any, any, any> {
    type: string;
    name: string;
    constructor(uppy: Uppy<any, any>, opts?: any);
    run(results: any): Promise<string>;
}
//# sourceMappingURL=invalidPluginWithoutId.d.ts.map