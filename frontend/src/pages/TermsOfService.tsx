import React from 'react';
import { Link } from 'react-router-dom';

export default function TermsOfService() {
  return (
    <div className="min-h-screen bg-white py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-4xl mx-auto">
        <div className="mb-8">
          <Link to="/" className="text-brand-primary hover:underline text-sm font-medium">
            &larr; Back to Home
          </Link>
        </div>

        <h1 className="text-4xl font-bold text-brand-primary mb-2">Terms of Service</h1>
        <p className="text-sm text-gray-500 mb-10">Last Updated: February 2026</p>

        {/* 1. Acceptance of Terms */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">1. Acceptance of Terms</h2>
          <p className="text-gray-700 leading-relaxed">
            By accessing or using Teams Elevated (the "Service"), operated by Athlete's Elevated LLC
            ("we," "us," or "our"), you agree to be bound by these Terms of Service ("Terms").
            If you do not agree to these Terms, you may not access or use the Service. If you
            are using the Service on behalf of an organization (such as a sports club), you
            represent that you have the authority to bind that organization to these Terms.
          </p>
        </section>

        {/* 2. Description of Service */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">2. Description of Service</h2>
          <p className="text-gray-700 leading-relaxed">
            Teams Elevated is a youth sports team management platform that provides tools for
            club administrators, coaches, parents, and athletes. The Service includes features
            for team roster management, athlete medical records, communication, event scheduling,
            payment processing, and other features related to the operation of youth athletic
            programs. We reserve the right to modify, suspend, or discontinue any aspect of the
            Service at any time with reasonable notice to users.
          </p>
        </section>

        {/* 3. User Accounts */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">3. User Accounts</h2>
          <p className="text-gray-700 leading-relaxed mb-4">
            To use certain features of the Service, you must create an account. When creating
            and maintaining your account, you agree to:
          </p>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed ml-4 space-y-2">
            <li>
              Provide accurate, current, and complete information during registration and keep
              your account information up to date.
            </li>
            <li>
              Maintain the confidentiality of your login credentials and not share your account
              with others.
            </li>
            <li>
              Accept responsibility for all activities that occur under your account.
            </li>
            <li>
              Notify us immediately if you suspect any unauthorized use of your account.
            </li>
          </ul>
          <p className="text-gray-700 leading-relaxed mt-4">
            We reserve the right to suspend or terminate accounts that violate these Terms or
            that have been inactive for an extended period, with prior notice to the account
            holder.
          </p>
        </section>

        {/* 4. Acceptable Use */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">4. Acceptable Use</h2>
          <p className="text-gray-700 leading-relaxed mb-4">
            You agree to use the Service only for lawful purposes and in accordance with these
            Terms. You agree not to:
          </p>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed ml-4 space-y-2">
            <li>
              Use the Service in any way that violates applicable federal, state, local, or
              international law or regulation.
            </li>
            <li>
              Attempt to gain unauthorized access to any part of the Service, other user
              accounts, or any systems or networks connected to the Service.
            </li>
            <li>
              Use the Service to transmit any malicious code, viruses, or other harmful
              materials.
            </li>
            <li>
              Collect or harvest personal information of other users without their consent.
            </li>
            <li>
              Use the Service to harass, abuse, or harm another person, or to impersonate any
              person or entity.
            </li>
            <li>
              Interfere with or disrupt the Service or the servers and networks used to provide
              the Service.
            </li>
          </ul>
        </section>

        {/* 5. Intellectual Property */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">5. Intellectual Property</h2>
          <p className="text-gray-700 leading-relaxed">
            The Service and its original content, features, and functionality are owned by
            Athlete's Elevated LLC and are protected by copyright, trademark, and other intellectual
            property laws. The Teams Elevated name, logo, and all related names, logos, product
            and service names, designs, and slogans are trademarks of Athlete's Elevated LLC. You may
            not use these marks without our prior written permission. You retain ownership of
            any content you submit through the Service, but you grant us a limited license to
            use, store, and display that content as necessary to provide the Service.
          </p>
        </section>

        {/* 6. Privacy */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">6. Privacy</h2>
          <p className="text-gray-700 leading-relaxed">
            Your use of the Service is also governed by our{' '}
            <Link to="/privacy-policy" className="text-brand-primary hover:underline font-medium">
              Privacy Policy
            </Link>
            , which describes how we collect, use, protect, and share your personal information.
            By using the Service, you consent to the data practices described in our Privacy
            Policy. We are committed to protecting the privacy of all users, especially children,
            and we comply with the Children's Online Privacy Protection Act (COPPA).
          </p>
        </section>

        {/* 7. Limitation of Liability */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">7. Limitation of Liability</h2>
          <p className="text-gray-700 leading-relaxed mb-4">
            To the fullest extent permitted by applicable law:
          </p>
          <ul className="list-disc list-inside text-gray-700 leading-relaxed ml-4 space-y-2">
            <li>
              The Service is provided on an "AS IS" and "AS AVAILABLE" basis without warranties
              of any kind, whether express or implied, including but not limited to warranties
              of merchantability, fitness for a particular purpose, or non-infringement.
            </li>
            <li>
              Athlete's Elevated LLC shall not be liable for any indirect, incidental, special,
              consequential, or punitive damages, including but not limited to loss of data,
              profits, or goodwill, arising out of or in connection with your use of the Service.
            </li>
            <li>
              Our total liability for any claims arising under these Terms shall not exceed the
              amount you have paid us in the twelve (12) months preceding the claim.
            </li>
          </ul>
          <p className="text-gray-700 leading-relaxed mt-4">
            Some jurisdictions do not allow the exclusion or limitation of certain damages, so
            some of the above limitations may not apply to you.
          </p>
        </section>

        {/* 8. Modifications to Terms */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">8. Modifications to Terms</h2>
          <p className="text-gray-700 leading-relaxed">
            We reserve the right to modify these Terms at any time. When we make changes, we
            will update the "Last Updated" date at the top of this page and notify users via
            email or through the Service. Your continued use of the Service after changes are
            posted constitutes your acceptance of the revised Terms. If you do not agree to the
            updated Terms, you must stop using the Service.
          </p>
        </section>

        {/* 9. Governing Law */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">9. Governing Law</h2>
          <p className="text-gray-700 leading-relaxed">
            These Terms shall be governed by and construed in accordance with the laws of the
            United States and the state in which Athlete's Elevated LLC is organized, without regard to
            its conflict of law provisions. Any disputes arising from or related to these Terms
            or the Service shall be resolved through binding arbitration in accordance with the
            rules of the American Arbitration Association, except that either party may seek
            injunctive or other equitable relief in any court of competent jurisdiction.
          </p>
        </section>

        {/* 10. Contact Information */}
        <section className="mb-10">
          <h2 className="text-2xl font-semibold text-brand-primary mb-4">10. Contact Information</h2>
          <p className="text-gray-700 leading-relaxed">
            If you have any questions about these Terms of Service, please contact us at:
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
