import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import LoadMore from './LoadMore';

/**
 * A paginated list that says nothing is a list that lies. These endpoints used
 * to return every row and now return 200; this control is the only thing on
 * screen distinguishing "the first 200" from "all of them".
 */
describe('LoadMore', () => {
  it('says there is more, and how much is on screen', () => {
    render(
      <LoadMore
        page={{ limit: 200, nextCursor: 'abc', truncated: true }}
        onLoadMore={() => {}}
        label="volunteers"
        shown={200}
      />
    );
    expect(screen.getByText(/Showing the first 200 volunteers/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Load more' })).toBeInTheDocument();
  });

  it('renders nothing on the last page', () => {
    const { container } = render(
      <LoadMore
        page={{ limit: 200, nextCursor: null, truncated: false }}
        onLoadMore={() => {}}
        label="volunteers"
        shown={12}
      />
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('renders nothing when the backend sent no page block', () => {
    // An older backend that is not paginating. Silence is correct there: there
    // genuinely is nothing more to fetch.
    const { container } = render(
      <LoadMore page={null} onLoadMore={() => {}} label="volunteers" shown={900} />
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('asks for the next page once per click and disables while loading', () => {
    const onLoadMore = jest.fn();
    const { rerender } = render(
      <LoadMore
        page={{ limit: 200, nextCursor: 'abc', truncated: true }}
        onLoadMore={onLoadMore}
        label="clubs"
        shown={200}
      />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Load more' }));
    expect(onLoadMore).toHaveBeenCalledTimes(1);

    rerender(
      <LoadMore
        page={{ limit: 200, nextCursor: 'abc', truncated: true }}
        onLoadMore={onLoadMore}
        loading
        label="clubs"
        shown={200}
      />
    );
    const button = screen.getByRole('button', { name: 'Loading…' });
    expect(button).toBeDisabled();
    fireEvent.click(button);
    expect(onLoadMore).toHaveBeenCalledTimes(1);
  });
});
