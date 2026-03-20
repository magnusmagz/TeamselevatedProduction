import React from 'react';
import { Link } from 'react-router-dom';

export default function PrivacyPolicy() {
  return (
    <div className="min-h-screen bg-white py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-4xl mx-auto">
        <div className="mb-8">
          <Link to="/" className="text-brand-primary hover:underline text-sm font-medium">
            &larr; Back to Home
          </Link>
        </div>

        <h1 className="text-4xl font-bold text-brand-primary mb-2">Privacy Policy</h1>
        <p className="text-sm text-gray-500 mb-10">Last Updated: February 2026</p>

        {/* 1. Introduction */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">1. Introduction</h2>
          <p className="text-gray-700 leading-relaxed">
            Teams Elevated, operated by Athlete's Elevated LLC ("we," "us," or "our"), is a youth sports
            team management platform designed to help clubs, coaches, parents, and athletes
            organize and manage youth athletic programs. This Privacy Policy explains how we
            collect, use, protect, and share personal information when you use our platform. By
            accessing or using Teams Elevated, you agree to the practices described in this policy.
          </p>
        </section>

        {/* 2. Information We Collect */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">2. Information We Collect</h2>
          <p className="text-gray-700 leading-relaxed mb-4">
            We collect information necessary to operate a youth sports team management platform.
            The categories of information we collect include:
          </p>

          <h3 className="text-lg font-semibold text-brand-primary mb-2">Personal Information</h3>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed mb-4 ml-4 space-y-1">
            <li>Names of athletes, parents/guardians, coaches, and administrators</li>
            <li>Dates of birth</li>
            <li>Home addresses</li>
            <li>Email addresses and phone numbers</li>
            <li>Emergency contact information</li>
            <li>Parent/guardian relationship details</li>
          </ul>

          <h3 className="text-lg font-semibold text-brand-primary mb-2">Medical Information</h3>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed mb-4 ml-4 space-y-1">
            <li>Known allergies</li>
            <li>Current medications</li>
            <li>Pre-existing medical conditions relevant to athletic participation</li>
            <li>Insurance information</li>
          </ul>

          <h3 className="text-lg font-semibold text-brand-primary mb-2">Usage Data</h3>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed ml-4 space-y-1">
            <li>Login and session information</li>
            <li>Device and browser type</li>
            <li>Pages visited and features used</li>
            <li>Payment transaction records</li>
          </ul>
        </section>

        {/* 3. How We Protect Your Information */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">3. How We Protect Your Information</h2>
          <p className="text-gray-700 leading-relaxed mb-4">
            We take the security of your data seriously and employ industry-standard measures to
            protect it:
          </p>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed ml-4 space-y-2">
            <li>
              <span className="font-medium">Encryption:</span> All sensitive medical data is
              encrypted using AES-256-GCM encryption, both in transit and at rest. This is one of
              the strongest encryption standards available.
            </li>
            <li>
              <span className="font-medium">Access Controls:</span> Role-based access controls
              ensure that users can only view information they are authorized to see based on
              their role (administrator, coach, parent, or athlete).
            </li>
            <li>
              <span className="font-medium">Audit Logging:</span> All access to sensitive data is
              logged and monitored. We maintain detailed audit trails of who accessed what
              information and when.
            </li>
            <li>
              <span className="font-medium">Secure Transmission:</span> All data transmitted
              between your device and our servers is encrypted using TLS (Transport Layer
              Security).
            </li>
          </ul>
        </section>

        {/* 4. Who Can Access Your Information */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">4. Who Can Access Your Information</h2>
          <p className="text-gray-700 leading-relaxed mb-4">
            Access to personal information is restricted based on role and need:
          </p>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed ml-4 space-y-2">
            <li>
              <span className="font-medium">Club Administrators:</span> Can access roster
              information, contact details, and medical data for athletes within their club for
              the purpose of program management and safety.
            </li>
            <li>
              <span className="font-medium">Coaches:</span> Can access athlete profiles and
              medical information for athletes on their assigned teams. Medical data is provided
              to coaches strictly for safety purposes during practices and events.
            </li>
            <li>
              <span className="font-medium">Parents and Guardians:</span> Can access and manage
              their own children's data, including profiles, medical records, and payment history.
            </li>
            <li>
              <span className="font-medium">Athletes:</span> Can view their own profile
              information as appropriate for their age.
            </li>
          </ul>
          <p className="text-gray-700 leading-relaxed mt-4">
            We do not sell, rent, or share personal information with third parties for marketing
            or advertising purposes.
          </p>
        </section>

        {/* 5. Children's Privacy (COPPA Compliance) */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">
            5. Children's Privacy (COPPA Compliance)
          </h2>
          <p className="text-gray-700 leading-relaxed mb-4">
            Teams Elevated is designed to serve youth athletic programs and we take the privacy of
            children very seriously. We comply with the Children's Online Privacy Protection Act
            (COPPA) and similar regulations:
          </p>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed ml-4 space-y-2">
            <li>
              <span className="font-medium">Parental Consent:</span> We collect personal
              information about children under 13 only with verifiable parental consent. A
              parent or guardian must create the account and provide information on behalf of
              their child.
            </li>
            <li>
              <span className="font-medium">Parental Access:</span> Parents and guardians can
              review all personal information collected about their children at any time through
              their parent portal. Parents may request corrections or deletion of their child's
              data.
            </li>
            <li>
              <span className="font-medium">Data Deletion:</span> Parents can request that their
              child's personal information be deleted from our systems. Upon receiving such a
              request, we will delete the data within 30 days, except where retention is required
              by law.
            </li>
            <li>
              <span className="font-medium">No Third-Party Marketing:</span> We never share
              children's personal information with third parties for marketing or commercial
              purposes.
            </li>
            <li>
              <span className="font-medium">Limited Collection:</span> We only collect
              information about minors that is reasonably necessary for participation in the
              youth athletic programs managed through our platform.
            </li>
          </ul>
        </section>

        {/* 6. Data Retention */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">6. Data Retention</h2>
          <p className="text-gray-700 leading-relaxed mb-4">
            We retain personal information only as long as necessary to fulfill the purposes for
            which it was collected:
          </p>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed ml-4 space-y-2">
            <li>
              <span className="font-medium">Active Accounts:</span> Data is retained for as long
              as the user's account remains active and the athlete is enrolled in a club program.
            </li>
            <li>
              <span className="font-medium">Inactive Accounts:</span> Accounts that have been
              inactive for more than 24 months will be flagged for review. Users will be notified
              before any data is purged.
            </li>
            <li>
              <span className="font-medium">Payment Records:</span> Transaction and payment
              records are retained for 7 years in accordance with financial record-keeping
              requirements.
            </li>
            <li>
              <span className="font-medium">Deletion Requests:</span> When a user requests data
              deletion, we will process the request within 30 days. Some data may be retained in
              anonymized form for analytics purposes.
            </li>
          </ul>
        </section>

        {/* 7. Your Rights */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">7. Your Rights</h2>
          <p className="text-gray-700 leading-relaxed mb-4">
            You have the following rights regarding your personal information:
          </p>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed ml-4 space-y-2">
            <li>
              <span className="font-medium">Right to Access:</span> You may request a copy of
              all personal information we hold about you or your child.
            </li>
            <li>
              <span className="font-medium">Right to Correction:</span> You may request that we
              correct any inaccurate or incomplete personal information.
            </li>
            <li>
              <span className="font-medium">Right to Deletion:</span> You may request that we
              delete your personal information, subject to legal retention requirements.
            </li>
            <li>
              <span className="font-medium">Right to Data Portability:</span> You may request
              your data in a commonly used, machine-readable format.
            </li>
          </ul>
          <p className="text-gray-700 leading-relaxed mt-4">
            To exercise any of these rights, please contact us at{' '}
            <a
              href="mailto:support@teamselevated.com"
              className="text-brand-primary hover:underline"
            >
              support@teamselevated.com
            </a>
            . We will respond to your request within 30 days.
          </p>
        </section>

        {/* 8. Contact Information */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">8. Contact Information</h2>
          <p className="text-gray-700 leading-relaxed">
            If you have any questions or concerns about this Privacy Policy or our data practices,
            please contact us at:
          </p>
          <div className="mt-4 text-gray-700">
            <p className="font-medium">Athlete's Elevated LLC</p>
            <p>Teams Elevated</p>
            <p>
              Email:{' '}
              <a
                href="mailto:support@teamselevated.com"
                className="text-brand-primary hover:underline"
              >
                support@teamselevated.com
              </a>
            </p>
          </div>
        </section>

        <div className="border-t border-gray-200 pt-6 mt-12 text-center text-sm text-gray-500">
          <p>&copy; {new Date().getFullYear()} Athlete's Elevated LLC. All rights reserved.</p>
        </div>
      </div>
    </div>
  );
}
