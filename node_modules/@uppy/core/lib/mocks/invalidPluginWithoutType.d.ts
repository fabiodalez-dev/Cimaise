import UIPlugin from '../UIPlugin.js';
import type Uppy from '../Uppy.js';
export default class InvalidPluginWithoutType extends UIPlugin<any, any, any> {
    id: string;
    name: string;
    constructor(uppy: Uppy<any, any>, opts?: any);
    run(results: any): Promise<string>;
}
//# sourceMappingURL=invalidPluginWithoutType.d.ts.map