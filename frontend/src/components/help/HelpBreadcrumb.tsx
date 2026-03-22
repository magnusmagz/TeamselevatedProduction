import React from 'react';
import { Link } from 'react-router-dom';

interface BreadcrumbItem {
  label: string;
  to?: string;
}

interface Props {
  items: BreadcrumbItem[];
}

const HelpBreadcrumb: React.FC<Props> = ({ items }) => {
  return (
    <nav className="flex items-center text-sm text-gray-500 mb-4">
      <Link to="/help" className="hover:text-brand-primary">Help</Link>
      {items.map((item, i) => (
        <React.Fragment key={i}>
          <svg className="w-4 h-4 mx-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
          </svg>
          {item.to ? (
            <Link to={item.to} className="hover:text-brand-primary">{item.label}</Link>
          ) : (
            <span className="text-brand-primary font-medium">{item.label}</span>
          )}
        </React.Fragment>
      ))}
    </nav>
  );
};

export default HelpBreadcrumb;
