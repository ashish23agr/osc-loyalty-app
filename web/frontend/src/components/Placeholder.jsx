/**
 * A screen the navigation reaches but Sprint 1 has not built yet.
 *
 * A stub rather than a missing route, so the menu has no dead links and a
 * reviewer clicking through the agreed structure can see what is coming and what
 * is not. It states which sprint owns the screen, because "coming soon" with no
 * date is the kind of placeholder that survives to launch.
 */
export default function Placeholder({ heading, subtitle, delivers, sprint, module: moduleName }) {
  return (
    <s-page heading={heading}>
      <s-section>
        <s-banner heading="Not built yet" tone="info">
          <s-paragraph>
            {subtitle} This screen is planned for {sprint}
            {moduleName ? ` (${moduleName})` : ''}.
          </s-paragraph>
        </s-banner>
      </s-section>

      {delivers?.length ? (
        <s-section heading="What this screen will do">
          <s-unordered-list>
            {delivers.map((item) => (
              <s-list-item key={item}>{item}</s-list-item>
            ))}
          </s-unordered-list>
        </s-section>
      ) : null}
    </s-page>
  );
}
