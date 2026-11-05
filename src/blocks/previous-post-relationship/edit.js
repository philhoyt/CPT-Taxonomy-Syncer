import AdjacentPostEdit from '../shared/adjacent-post-edit';

/**
 * Editor UI for the Previous Post (Relationship) block.
 *
 * @param {Object} props Block props.
 * @return {Element} Element to render.
 */
export default function Edit( props ) {
	return <AdjacentPostEdit { ...props } direction="previous" />;
}
