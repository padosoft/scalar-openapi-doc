import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            {/* A "</>" developer-docs mark; paths use SVG fill (inherits fill-current). */}
            <path d="M8.78 6.3a1 1 0 0 1 .013 1.414L4.84 11.78l3.953 4.066a1 1 0 1 1-1.434 1.394l-4.633-4.764a1 1 0 0 1 0-1.394l4.633-4.763A1 1 0 0 1 8.78 6.3Z" />
            <path d="M15.22 6.3a1 1 0 0 0-.013 1.414l3.953 4.066-3.953 4.066a1 1 0 1 0 1.434 1.394l4.633-4.764a1 1 0 0 0 0-1.394l-4.633-4.763A1 1 0 0 0 15.22 6.3Z" />
            <path d="M13.74 4.04a1 1 0 0 1 .722 1.218l-3.2 12.4a1 1 0 1 1-1.937-.5l3.2-12.4a1 1 0 0 1 1.215-.718Z" />
        </svg>
    );
}
