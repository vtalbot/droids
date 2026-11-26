import type { SVGAttributes } from 'react';

/** Droid head mark: hull with a visor cut-out and two eye lights. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <rect x="22" y="3" width="4" height="9" rx="2" />
            <circle cx="24" cy="4.5" r="3.5" />
            <path
                fillRule="evenodd"
                d="M16 12h16a8 8 0 0 1 8 8v12a8 8 0 0 1-8 8H16a8 8 0 0 1-8-8V20a8 8 0 0 1 8-8Zm3 8a5 5 0 0 0 0 10h10a5 5 0 0 0 0-10Z"
            />
            <circle cx="19.5" cy="25" r="2.5" />
            <circle cx="28.5" cy="25" r="2.5" />
        </svg>
    );
}
