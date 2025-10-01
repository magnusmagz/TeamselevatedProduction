import React from 'react';
import { Link } from 'react-router-dom';

export default function Home() {
  return (
    <div className="min-h-screen bg-gradient-to-b from-green-50 to-white">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-20 pb-16">
        <div className="text-center">
          <h1 className="text-5xl font-bold text-gray-900 mb-6">
            The Complete Team Management Platform
          </h1>
          <p className="text-xl text-gray-600 mb-8 max-w-3xl mx-auto">
            Transform how your sports team connects, collaborates, and grows together with professional branding, seamless communication, and enterprise-grade management tools
          </p>

          <div className="space-y-4">
            <div className="space-x-4">
              <Link
                to="/get-started"
                className="inline-block bg-forest-600 hover:bg-forest-700 text-white font-semibold py-3 px-8 transition-colors duration-200"
              >
                Get Started
              </Link>
              <Link
                to="/login"
                className="inline-block bg-white hover:bg-gray-50 text-forest-600 font-semibold py-3 px-8 border-2 border-forest-600 transition-colors duration-200"
              >
                Sign In
              </Link>
            </div>
            <p className="text-sm text-gray-500">
              No sign-up required! Get started with magic link authentication.
            </p>
          </div>
        </div>

        <div className="mt-20 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
          <div className="bg-white shadow-sm border-2 border-gray-200 p-6">
            <div className="w-12 h-12 bg-forest-100 flex items-center justify-center mb-4">
              <svg className="w-6 h-6 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zM21 5a2 2 0 00-2-2h-4a2 2 0 00-2 2v12a4 4 0 004 4 4 4 0 004-4V5z" />
              </svg>
            </div>
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Professional Branding</h3>
            <p className="text-gray-600">
              Upload your team logo and automatically generate a cohesive brand theme with AI-powered color extraction
            </p>
          </div>

          <div className="bg-white shadow-sm border-2 border-gray-200 p-6">
            <div className="w-12 h-12 bg-forest-100 flex items-center justify-center mb-4">
              <svg className="w-6 h-6 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
              </svg>
            </div>
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Integrated Team Chat</h3>
            <p className="text-gray-600">
              Seamless team communication with single sign-on chat integration and role-based access
            </p>
          </div>

          <div className="bg-white shadow-sm border-2 border-gray-200 p-6">
            <div className="w-12 h-12 bg-forest-100 flex items-center justify-center mb-4">
              <svg className="w-6 h-6 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
            </div>
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Real-Time Analytics</h3>
            <p className="text-gray-600">
              Comprehensive dashboard with member management, invitation tracking, and team analytics
            </p>
          </div>

          <div className="bg-white shadow-sm border-2 border-gray-200 p-6">
            <div className="w-12 h-12 bg-forest-100 flex items-center justify-center mb-4">
              <svg className="w-6 h-6 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
            </div>
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Smart Invitations</h3>
            <p className="text-gray-600">
              Send branded email invitations or shareable links with automatic user reconciliation and role assignment
            </p>
          </div>

          <div className="bg-white shadow-sm border-2 border-gray-200 p-6">
            <div className="w-12 h-12 bg-forest-100 flex items-center justify-center mb-4">
              <svg className="w-6 h-6 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
              </svg>
            </div>
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Protected & Professional</h3>
            <p className="text-gray-600">
              We guard your data with professional-grade security and safe storage so your team can collaborate with peace of mind.
            </p>
          </div>

          <div className="bg-white shadow-sm border-2 border-gray-200 p-6">
            <div className="w-12 h-12 bg-forest-100 flex items-center justify-center mb-4">
              <svg className="w-6 h-6 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Zero-Friction Joining</h3>
            <p className="text-gray-600">
              Password-free magic link authentication for invited members with instant team access
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
