import isDOMElement from './isDOMElement.js';
function findDOMElement(element, context = document) {
    if (typeof element === 'string') {
        return context.querySelector(element);
    }
    if (isDOMElement(element)) {
        return element;
    }
    return null;
}
export default findDOMElement;
